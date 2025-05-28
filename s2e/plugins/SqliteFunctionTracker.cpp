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

#include "SqliteFunctionTracker.h"

uint64_t dbh_ptr;

namespace s2e
{
    namespace plugins
    {

        namespace
        {

            class SqliteFunctionTrackerState : public PluginState
            {
                // Declare any methods and fields you need here

            public:
                static PluginState *factory(Plugin *p, S2EExecutionState *s)
                {
                    return new SqliteFunctionTrackerState();
                }

                virtual ~SqliteFunctionTrackerState()
                {
                    // Destroy any object if needed
                }

                virtual SqliteFunctionTrackerState *clone() const
                {
                    return new SqliteFunctionTrackerState(*this);
                }
            };

        } // namespace

        S2E_DEFINE_PLUGIN(SqliteFunctionTracker, "Describe what the plugin does here", "", );

        void SqliteFunctionTracker::initialize()
        {
            m_address = (uint64_t)s2e()->getConfig()->getInt(getConfigKey() + ".addressToTrack");

            s2e()->getPlugin<FunctionMonitor>()->onCall.connect(sigc::mem_fun(*this, &SqliteFunctionTracker::onCall));
        }

        void SqliteFunctionTracker::onCall(S2EExecutionState *state, const ModuleDescriptorConstPtr &source,
                                           const ModuleDescriptorConstPtr &dest, uint64_t callerPc, uint64_t calleePc,
                                           const FunctionMonitor::ReturnSignalPtr &returnSignal)
        {
            if (state->regs()->getPc() != m_address)
            {
                return;
            }

            getDebugStream(state) << "Execution entered function " << hexval(m_address) << "\n";

            S2EExecutionStateRegisters *regs = state->regs();
            // use second parameter to get query string
            // type: zend_string
            uint64_t arg_sql = regs->read<uint64_t>(offsetof(CPUX86State, regs[R_ESI]));
            dbh_ptr = regs->read<uint64_t>(offsetof(CPUX86State, regs[R_EDI]));

            uint64_t len_address = arg_sql + 0x10;
            uint64_t len;
            state->mem()->read<uint64_t>(len_address, &len);

            uint64_t zend_string_val_address = arg_sql + 0x18;
            bool found = false;
            for (unsigned i = 0; i < len; ++i)
            {
                uint8_t chr;
                state->mem()->read<uint8_t>(zend_string_val_address + i, &chr);
                if (!isascii(chr))
                {
                    found = true;
                    break;
                }
            }

            std::string zend_string_val;
            state->mem()->readString(zend_string_val_address, zend_string_val, len);
            if (found)
            {
                getDebugStream(state) << "Received possible symbolic memory access\n";
                generateTestCases(state);
            }
            getDebugStream(state) << "Received string: " << zend_string_val << "\n";

            // returnSignal->connect(sigc::bind(sigc::mem_fun(*this, &SqliteFunctionTracker::onRet), m_address));
        }

        void SqliteFunctionTracker::generateTestCases(S2EExecutionState *state)
        {
            ConcreteInputs inputs;
            bool success = state->getSymbolicSolution(inputs);

            if (!success)
            {
                getWarningsStream(state) << "Could not get symbolic solutions" << '\n';
                return;
            }

            writeSimpleTestCase(getDebugStream(state), inputs);
        }

        void SqliteFunctionTracker::writeSimpleTestCase(llvm::raw_ostream &os, const ConcreteInputs &inputs)
        {
            std::stringstream ss;
            ConcreteInputs::const_iterator it;
            ss << "Test case: ";
            for (it = inputs.begin(); it != inputs.end(); ++it)
            {
                const VarValuePair &vp = *it;
                ss << std::setw(20) << vp.first << " = {";

                for (unsigned i = 0; i < vp.second.size(); ++i)
                {
                    if (i != 0)
                        ss << ", ";
                    ss << std::setw(2) << std::setfill('0') << "0x" << std::hex << (unsigned)vp.second[i] << std::dec;
                }
                ss << "}" << std::setfill(' ') << "; ";

                if (vp.second.size() == sizeof(int32_t))
                {
                    int32_t valueAsInt = vp.second[0] | ((int32_t)vp.second[1] << 8) | ((int32_t)vp.second[2] << 16) |
                                         ((int32_t)vp.second[3] << 24);
                    ss << "(int32_t) " << valueAsInt << ", ";
                }
                if (vp.second.size() == sizeof(int64_t))
                {
                    int64_t valueAsInt = vp.second[0] | ((int64_t)vp.second[1] << 8) | ((int64_t)vp.second[2] << 16) |
                                         ((int64_t)vp.second[3] << 24) | ((int64_t)vp.second[4] << 32) |
                                         ((int64_t)vp.second[5] << 40) | ((int64_t)vp.second[6] << 48) |
                                         ((int64_t)vp.second[7] << 56);
                    ss << "(int64_t) " << valueAsInt << ", ";
                }

                ss << "(string) \"";
                for (unsigned i = 0; i < vp.second.size(); ++i)
                {
                    if (std::isprint(vp.second[i]))
                    {
                        ss << (char)vp.second[i];
                    }
                    else if (vp.second[i] == 0)
                    {
                        break;
                    }
                    else
                    {
                        ss << ".";
                    }
                }
                ss << "\"";
            }
            ss << "\n";

            os << ss.str();
        }

        // void SqliteFunctionTracker::onRet(S2EExecutionState *state, const ModuleDescriptorConstPtr &source,
        //                                   const ModuleDescriptorConstPtr &dest, uint64_t returnSite, uint64_t functionPc) {
        //     getDebugStream(state) << "Execution returned from function " << hexval(functionPc) << "\n";

        //     S2EExecutionStateRegisters *regs = state->regs();
        //     uint64_t ret = regs->read<uint64_t>(offsetof(CPUX86State, regs[R_EAX]));

        //     if (ret != 0) {
        //         return;
        //     }

        //     uint64_t error_code_address = dbh_ptr + 0x38;
        //     std::string err_code_str;
        //     if (!state->mem()->readString(error_code_address, err_code_str, 6)) {
        //         getDebugStream(state) << "Failed to read error message string\n";
        //         return;
        //     }

        //     getDebugStream(state) << "Error message: " << err_code_str << "\n";

        //     if (err_code_str.find("HY000") != std::string::npos) {
        //         getDebugStream(state) << "[!!] SQL Error detected: HY000\n";
        //     }
        // }

        // implementations for MySQL
        /*
        // void SqliteFunctionTracker::onCall(S2EExecutionState *state, const ModuleDescriptorConstPtr &source,
        //                                    const ModuleDescriptorConstPtr &dest, uint64_t callerPc, uint64_t calleePc,
        //                                    const FunctionMonitor::ReturnSignalPtr &returnSignal) {
        //     if (state->regs()->getPc() != m_address) {
        //         return;
        //     }

        //     getDebugStream(state) << "Execution entered function " << hexval(m_address) << "\n";

        //     S2EExecutionStateRegisters *regs = state->regs();
        //     // use second parameter to get query string
        //     // type: zend_string
        //     uint64_t arg_query = regs->read<uint64_t>(offsetof(CPUX86State, regs[R_ESI]));
        //     uint64_t arg_query_len = regs->read<uint64_t>(offsetof(CPUX86State, regs[R_EDX]));

        //     if (state->mem()->symbolic(arg_query, arg_query_len)) {
        //         getDebugStream(state) << "Received possible symbolic memory access\n";
        //     } else {
        //         std::string query_string;
        //         state->mem()->readString(arg_query, query_string, arg_query_len);
        //         getDebugStream(state) << "Received string: " << query_string << "\n";
        //     }

        //     // returnSignal->connect(sigc::bind(sigc::mem_fun(*this, &SqliteFunctionTracker::onRet), m_address));
        // }

        // void SqliteFunctionTracker::onRet(S2EExecutionState *state, const ModuleDescriptorConstPtr &source,
        //                                   const ModuleDescriptorConstPtr &dest, uint64_t returnSite, uint64_t functionPc) {
        //     getDebugStream(state) << "Execution returned from function " << hexval(functionPc) << "\n";

        //     S2EExecutionStateRegisters *regs = state->regs();
        //     uint64_t ret = regs->read<uint64_t>(offsetof(CPUX86State, regs[R_EAX]));

        //     if (ret != 0) {
        //         return;
        //     }

        //     uint64_t error_code_address = dbh_ptr + 0x38;
        //     std::string err_code_str;
        //     if (!state->mem()->readString(error_code_address, err_code_str, 6)) {
        //         getDebugStream(state) << "Failed to read error message string\n";
        //         return;
        //     }

        //     getDebugStream(state) << "Error message: " << err_code_str << "\n";

        //     if (err_code_str.find("HY000") != std::string::npos) {
        //         getDebugStream(state) << "[!!] SQL Error detected: HY000\n";
        //     }
        // }
        */

        void SqliteFunctionTracker::handleOpcodeInvocation(S2EExecutionState *state, uint64_t guestDataPtr,
                                                           uint64_t guestDataSize)
        {
            S2E_SQLITEFUNCTIONTRACKER_COMMAND command;

            if (guestDataSize != sizeof(command))
            {
                getWarningsStream(state) << "mismatched S2E_SQLITEFUNCTIONTRACKER_COMMAND size\n";
                return;
            }

            if (!state->mem()->read(guestDataPtr, &command, guestDataSize))
            {
                getWarningsStream(state) << "could not read transmitted data\n";
                return;
            }

            switch (command.Command)
            {
            // TODO: add custom commands here
            case COMMAND_1:
                break;
            default:
                getWarningsStream(state) << "Unknown command " << command.Command << "\n";
                break;
            }
        }

    } // namespace plugins
} // namespace s2e
