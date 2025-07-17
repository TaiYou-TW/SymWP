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

#include "InternedStringTracker.h"

namespace s2e {
namespace plugins {

namespace {

class InternedStringTrackerState : public PluginState {
    // Declare any methods and fields you need here

public:
    static PluginState *factory(Plugin *p, S2EExecutionState *s) {
        return new InternedStringTrackerState();
    }

    virtual ~InternedStringTrackerState() {
        // Destroy any object if needed
    }

    virtual InternedStringTrackerState *clone() const {
        return new InternedStringTrackerState(*this);
    }
};

} // namespace

S2E_DEFINE_PLUGIN(InternedStringTracker, "Describe what the plugin does here", "", );

void InternedStringTracker::initialize() {
    addresses = s2e()->getConfig()->getIntegerList(getConfigKey() + ".addressesToTrack");

    s2e()->getPlugin<FunctionMonitor>()->onCall.connect(sigc::mem_fun(*this, &InternedStringTracker::onCall));
}

void InternedStringTracker::onCall(S2EExecutionState *state, const ModuleDescriptorConstPtr &source,
                                   const ModuleDescriptorConstPtr &dest, uint64_t callerPc, uint64_t calleePc,
                                   const FunctionMonitor::ReturnSignalPtr &returnSignal) {
    bool found = false;
    foreach2 (address, addresses.begin(), addresses.end()) {
        if (state->regs()->getPc() == (unsigned long) *address) {
            found = true;
        }
    }
    if (!found) {
        return;
    }

    S2EExecutionStateRegisters *regs = state->regs();
    // uint64_t arg1 = regs->read<uint64_t>(offsetof(CPUX86State, regs[R_EDI])); // pointer to the buffer
    uint64_t arg_len = regs->read<uint64_t>(offsetof(CPUX86State, regs[R_ESI])); // length

    if (arg_len == 1) {
        getDebugStream(state) << "Received interned string!\n";
    }
}

void InternedStringTracker::handleOpcodeInvocation(S2EExecutionState *state, uint64_t guestDataPtr,
                                                   uint64_t guestDataSize) {
    S2E_INTERNEDSTRINGTRACKER_COMMAND command;

    if (guestDataSize != sizeof(command)) {
        getWarningsStream(state) << "mismatched S2E_INTERNEDSTRINGTRACKER_COMMAND size\n";
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

} // namespace plugins
} // namespace s2e
