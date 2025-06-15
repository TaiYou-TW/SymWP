///
/// Copyright (C) 2025, TaiYou
///
/// Permission is hereby granted, free of charge, to any person obtaining a copy
/// of this software and associated documentation files (the "Software"), to deal
/// in the Software without restriction, including without limitation the rights
/// to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
/// copies of the Software, and to permit persons to whom the Software is
/// furnished to do so, subject to the following conditions:
///
/// The above copyright notice and this permission notice shall be included in all
/// copies or substantial portions of the Software.
///
/// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
/// IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
/// FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
/// AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
/// LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
/// OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
/// SOFTWARE.
///

#include <s2e/ConfigFile.h>
#include <s2e/Plugins/ExecutionMonitors/FunctionMonitor.h>
#include <s2e/Plugins/OSMonitors/ModuleDescriptor.h>
#include <s2e/S2E.h>
#include <s2e/Utils.h>
#include <s2e/cpu.h>

#include <klee/util/ExprUtil.h>

#include "EchoFunctionTracker.h"

namespace s2e {
namespace plugins {

namespace {

//
// This class can optionally be used to store per-state plugin data.
//
// Use it as follows:
// void EchoFunctionTracker::onEvent(S2EExecutionState *state, ...) {
//     DECLARE_PLUGINSTATE(EchoFunctionTrackerState, state);
//     plgState->...
// }
//
class EchoFunctionTrackerState : public PluginState {
    // Declare any methods and fields you need here

public:
    static PluginState *factory(Plugin *p, S2EExecutionState *s) {
        return new EchoFunctionTrackerState();
    }

    virtual ~EchoFunctionTrackerState() {
        // Destroy any object if needed
    }

    virtual EchoFunctionTrackerState *clone() const {
        return new EchoFunctionTrackerState(*this);
    }
};

} // namespace

S2E_DEFINE_PLUGIN(EchoFunctionTracker, "Describe what the plugin does here", "", );

void EchoFunctionTracker::initialize() {
    m_address = (uint64_t) s2e()->getConfig()->getInt(getConfigKey() + ".addressToTrack");

    s2e()->getCorePlugin()->onSymbolicVariableCreation.connect(
        sigc::mem_fun(*this, &EchoFunctionTracker::onSymbolicVariableCreation));
    s2e()->getPlugin<FunctionMonitor>()->onCall.connect(sigc::mem_fun(*this, &EchoFunctionTracker::onCall));
}

void EchoFunctionTracker::onCall(S2EExecutionState *state, const ModuleDescriptorConstPtr &source,
                                 const ModuleDescriptorConstPtr &dest, uint64_t callerPc, uint64_t calleePc,
                                 const FunctionMonitor::ReturnSignalPtr &returnSignal) {
    if (state->regs()->getPc() != m_address) {
        return;
    }

    // getDebugStream(state) << "Execution entered function " << hexval(m_address) << "\n";

    S2EExecutionStateRegisters *regs = state->regs();
    uint64_t arg1 = regs->read<uint64_t>(offsetof(CPUX86State, regs[R_EDI])); // pointer to the buffer
    uint64_t arg2 = regs->read<uint64_t>(offsetof(CPUX86State, regs[R_ESI])); // length

    if (state->mem()->symbolic(arg1, arg2)) {
        // s2e()->getExecutor()->terminateState(*state, "[" + hexval(m_address).str() + "] Symbolic memory access");
        getDebugStream(state) << "[" << hexval(m_address) << "] Received symbolic memory access\n";
        getDebugStream(state) << "[" << hexval(m_address) << "] arg1: " << hexval(arg1) << "\n";

        printExploitableSymbolicArgs(state, arg1, arg2);

        // addConstraintToSymbolicString(state, arg1, arg2);
    } else {
        std::string str;
        state->mem()->readString(arg1, str, arg2);
        getDebugStream(state) << "[" << hexval(m_address) << "] Received string: " << str << "\n";
        // s2e()->getExecutor()->terminateState(*state,
        //                                      "[" + hexval(m_address).str() + "] Non-symbolic memory access:" + str);
    }

    // If you do not want to track returns, do not connect a return signal.
    // Here, we pass the program counter to the return handler to identify the function
    // from which execution returns.
    // returnSignal->connect(sigc::bind(sigc::mem_fun(*this, &EchoFunctionTracker::onRet), m_address));
}

void EchoFunctionTracker::onRet(S2EExecutionState *state, const ModuleDescriptorConstPtr &source,
                                const ModuleDescriptorConstPtr &dest, uint64_t returnSite, uint64_t functionPc) {
    getDebugStream(state) << "Execution returned from function " << hexval(functionPc) << "\n";
}

void EchoFunctionTracker::onSymbolicVariableCreation(S2EExecutionState *state, const std::string &name,
                                                     const std::vector<klee::ref<klee::Expr>> &expr,
                                                     const klee::ArrayPtr &array) {
    uint64_t address;
    std::string arrayName = array->getName();
    state->regs()->read(CPU_OFFSET(regs[R_EAX]), &address, sizeof(address), false);
    getDebugStream(state) << "Symbolic variable created: " << arrayName << "\n";
    getDebugStream(state) << "  Address: " << hexval(address) << "\n";

    // byte is printable OR zero
    for (uint64_t i = 0; i < array->getSize(); ++i) {
        klee::ref<klee::Expr> byteExpr = state->mem()->read(address + i);
        if (byteExpr) {
            klee::ref<klee::Expr> ge20 =
                klee::SgeExpr::create(byteExpr, klee::ConstantExpr::create(0x20, byteExpr->getWidth()));
            klee::ref<klee::Expr> le7E =
                klee::SleExpr::create(byteExpr, klee::ConstantExpr::create(0x7E, byteExpr->getWidth()));
            klee::ref<klee::Expr> asciiPrintable = klee::AndExpr::create(ge20, le7E);

            klee::ref<klee::Expr> isNull =
                klee::EqExpr::create(byteExpr, klee::ConstantExpr::create(0, byteExpr->getWidth()));

            klee::ref<klee::Expr> validChar = klee::OrExpr::create(asciiPrintable, isNull);

            if (!state->addConstraint(validChar, true)) {
                s2e()->getExecutor()->terminateState(*state, "Tried to add an invalid constraint");
            }
        }
    }
}

void EchoFunctionTracker::handleOpcodeInvocation(S2EExecutionState *state, uint64_t guestDataPtr,
                                                 uint64_t guestDataSize) {
    S2E_ECHOFUNCTIONTRACKER_COMMAND command;

    if (guestDataSize != sizeof(command)) {
        getWarningsStream(state) << "mismatched S2E_ECHOFUNCTIONTRACKER_COMMAND size\n";
        return;
    }

    if (!state->mem()->read(guestDataPtr, &command, guestDataSize)) {
        getWarningsStream(state) << "could not read transmitted data\n";
        return;
    }

    switch (command.Command) {
        // TODO: add custom commands here
        case COMMAND_1:
            break;
        default:
            getWarningsStream(state) << "Unknown command " << command.Command << "\n";
            break;
    }
}

void EchoFunctionTracker::printExploitableSymbolicArgs(S2EExecutionState *state, uint64_t address, uint64_t size) {
    std::set<std::string> foundNames;

    for (uint64_t i = 0; i < size; i++) {
        klee::ref<klee::Expr> byteExpr = state->mem()->read(address + i);
        if (byteExpr) {
            getDebugStream(state) << "Value: " << byteExpr << "\n";
            std::vector<klee::ref<klee::ReadExpr>> reads;
            klee::findReads(byteExpr, false, reads);

            for (const auto &read : reads) {
                auto &array = read->getUpdates()->getRoot();
                foundNames.insert(array->getName());
            }
        }
    }

    generateTestCases(state, foundNames);
}

// FIXME: buggy function, will crash the engine with unknown reason
void EchoFunctionTracker::addConstraintToSymbolicString(S2EExecutionState *state, uint64_t address, uint64_t size) {
    bool foundNull = false;
    for (uint64_t i = 0; i < size; ++i) {
        getDebugStream(state) << "Checking memory at " << hexval(address + i) << "\n";
        klee::ref<klee::Expr> byteExpr = state->mem()->read(address + i);
        getDebugStream(state) << "Reading memory at " << hexval(address + i) << "\n";
        getDebugStream(state) << "  Value: " << byteExpr << "\n";
        if (byteExpr == NULL) {
            continue;
        }

        if (foundNull) {
            getDebugStream(state) << "FoundNull " << i << "\n";
            klee::ref<klee::Expr> nullExpr = klee::ConstantExpr::create(0, byteExpr->getWidth());
            klee::ref<klee::Expr> constraint = klee::EqExpr::create(byteExpr, nullExpr);
            if (!state->addConstraint(constraint)) {
                s2e()->getExecutor()->terminateState(*state, "Tried to add an invalid constraint");
            }
            getDebugStream(state) << "Adding constraint: " << byteExpr << " == " << nullExpr << "\n";
            continue;
        }
        getDebugStream(state) << "Checking if byte is concrete or symbolic\n";
        // concrete value
        if (byteExpr->isZero()) {
            foundNull = true;
            getDebugStream(state) << "Found null byte at offset " << i << "\n";
            continue;
        } else if (!isa<klee::ConstantExpr>(byteExpr)) {
            // symbolic value
            // if possible solution may be zero, fork the state to add constraints
            getDebugStream(state) << "Found symbolic byte: " << byteExpr << " at offset " << i << "\n";
            klee::ref<klee::Expr> value = state->concolics->evaluate(byteExpr);
            if (value == 0) {
                foundNull = true;
                getDebugStream(state) << "Found symbolic zero byte at offset " << i << "\n";
            } else {
                getDebugStream(state) << "Found symbolic non-zero byte: " << value << " at offset " << i << "\n";
            }
        }
    }
}

void EchoFunctionTracker::generateTestCases(S2EExecutionState *state, std::set<std::string> foundNames) {
    ConcreteInputs inputs;
    bool success = state->getSymbolicSolution(inputs);

    if (!success) {
        getWarningsStream(state) << "Could not get symbolic solutions" << '\n';
        return;
    }

    writeSimpleTestCase(getDebugStream(state), inputs, foundNames);
}

void EchoFunctionTracker::writeSimpleTestCase(llvm::raw_ostream &os, const ConcreteInputs &inputs,
                                              std::set<std::string> foundNames) {
    std::stringstream ss;
    ConcreteInputs::const_iterator it;
    ss << "Test case: ";
    for (it = inputs.begin(); it != inputs.end(); ++it) {
        const VarValuePair &vp = *it;

        bool found = false;
        for (const auto &argName : foundNames) {
            if (vp.first.compare(argName) == 0) {
                found = true;
                break;
            }
        }

        ss << std::setw(20) << vp.first;
        if (found) {
            ss << "(exploitable)";
        }
        ss << " = {";

        for (unsigned i = 0; i < vp.second.size(); ++i) {
            if (i != 0)
                ss << ", ";
            ss << std::setw(2) << std::setfill('0') << "0x" << std::hex << (unsigned) vp.second[i] << std::dec;
        }
        ss << "}" << std::setfill(' ') << "; ";

        if (vp.second.size() == sizeof(int32_t)) {
            int32_t valueAsInt = vp.second[0] | ((int32_t) vp.second[1] << 8) | ((int32_t) vp.second[2] << 16) |
                                 ((int32_t) vp.second[3] << 24);
            ss << "(int32_t) " << valueAsInt << ", ";
        }
        if (vp.second.size() == sizeof(int64_t)) {
            int64_t valueAsInt = vp.second[0] | ((int64_t) vp.second[1] << 8) | ((int64_t) vp.second[2] << 16) |
                                 ((int64_t) vp.second[3] << 24) | ((int64_t) vp.second[4] << 32) |
                                 ((int64_t) vp.second[5] << 40) | ((int64_t) vp.second[6] << 48) |
                                 ((int64_t) vp.second[7] << 56);
            ss << "(int64_t) " << valueAsInt << ", ";
        }

        ss << "(string) \"";
        for (unsigned i = 0; i < vp.second.size(); ++i) {
            if (std::isprint(vp.second[i])) {
                ss << (char) vp.second[i];
            } else if (vp.second[i] == 0) {
                break;
            }
        }
        ss << "\"";
    }
    ss << "\n";

    os << ss.str();
}

} // namespace plugins
} // namespace s2e