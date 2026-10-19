# Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
# Offline test for tokens.py --counter claude: a stand-in anthropic module counts one token per character
# and two tokens of message frame, so the real API is never called.
import os, sys, types

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
calls = []


class Messages:
    def count_tokens(self, model, messages):
        calls.append(model)
        return types.SimpleNamespace(input_tokens=len(messages[0]["content"]) + 2)


fake = types.ModuleType("anthropic")
fake.Anthropic = lambda: types.SimpleNamespace(messages=Messages())
sys.modules["anthropic"] = fake
import tokens  # noqa: E402

count = tokens.make_counter("claude", "claude-opus-5-5")
assert count("hello") == 5 and count("") == 0, "the frame is taken off every count"
assert set(calls) == {"claude-opus-5-5"}
print("ok tokens")
