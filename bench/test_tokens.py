# Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
# Offline test for tokens.py --counter claude: a stand-in anthropic module counts one token per character
# and two tokens of message frame, so the real API is never called.
import os, sys, types

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
calls = []
fail = []  # the error the next count_tokens call raises


class APIStatusError(Exception):
    def __init__(self, message):
        super().__init__(message)
        self.message = message


class AuthenticationError(APIStatusError):
    pass


class RateLimitError(APIStatusError):
    pass


class Messages:
    def count_tokens(self, model, messages):
        if fail:
            raise fail.pop()
        assert messages[0]["content"], "the API refuses an empty text block"
        calls.append(model)
        return types.SimpleNamespace(input_tokens=len(messages[0]["content"]) + 2)


fake = types.ModuleType("anthropic")
fake.Anthropic = lambda **kw: calls.append(kw) or types.SimpleNamespace(messages=Messages())
fake.APIStatusError, fake.AuthenticationError, fake.RateLimitError = APIStatusError, AuthenticationError, RateLimitError
sys.modules["anthropic"] = fake
import tokens  # noqa: E402

count = tokens.make_counter("claude", "claude-opus-5-5")
assert calls[0]["max_retries"] > 2, "more retries than the SDK default, for a 429"
assert count("hello") == 5 and count("") == 0, "the frame is taken off every count"
assert calls[1:] == ["claude-opus-5-5"] * 2, "the frame and hello, and no request for the empty text"


def dies(error, words):
    """make_counter or a count ends with one clear message, not a traceback."""
    fail.append(error)
    try:
        tokens.make_counter("claude", "claude-opus-5-5")
    except SystemExit as e:
        assert all(w in str(e) for w in words), e
        return
    raise AssertionError(f"no SystemExit for {error!r}")


dies(TypeError("Could not resolve authentication method. Expected one of api_key, auth_token, or credentials to be set."), ["ANTHROPIC_API_KEY", "ant auth login"])
dies(AuthenticationError("invalid x-api-key"), ["refused the credentials", "invalid x-api-key"])
dies(RateLimitError("rate_limit_error"), ["rate limited", "run it again"])
fail.append(TypeError("unrelated"))
try:
    tokens.make_counter("claude")
    raise AssertionError("another TypeError is no credentials message")
except TypeError:
    pass
print("ok tokens")
