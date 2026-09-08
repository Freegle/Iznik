package assistant

import (
	"context"
	"errors"
	"os"
	"strings"
	"time"

	"github.com/anthropics/anthropic-sdk-go"
	"github.com/anthropics/anthropic-sdk-go/option"
)

// SayExtractor pulls the first "say": "..." string out of a JSON stream as it arrives,
// unescaping it, so the member sees Freegle typing while the decision is still coming.
type SayExtractor struct {
	onDelta func(string)
	buf     string
	state   int // 0 seek, 1 colon, 2 open, 3 in, 4 done
	escape  bool
	unicode []rune
}

func NewSayExtractor(onDelta func(string)) *SayExtractor {
	return &SayExtractor{onDelta: onDelta}
}

func (x *SayExtractor) Push(chunk string) {
	for _, ch := range chunk {
		x.char(ch)
	}
}

func (x *SayExtractor) char(ch rune) {
	switch x.state {
	case 4:
		return
	case 0:
		x.buf += string(ch)
		if len(x.buf) > 64 {
			x.buf = x.buf[len(x.buf)-64:]
		}
		trimmed := strings.TrimRight(x.buf, " \t\r\n")
		if strings.HasSuffix(trimmed, `"say"`) {
			x.state = 1
		}
	case 1:
		if ch == ':' {
			x.state = 2
		} else if ch != ' ' && ch != '\t' && ch != '\n' && ch != '\r' {
			x.state = 0
		}
	case 2:
		if ch == '"' {
			x.state = 3
		} else if ch != ' ' && ch != '\t' && ch != '\n' && ch != '\r' {
			x.state = 0
		}
	case 3:
		if x.unicode != nil {
			x.unicode = append(x.unicode, ch)
			if len(x.unicode) == 4 {
				var v rune
				for _, h := range x.unicode {
					v = v*16 + hexVal(h)
				}
				x.onDelta(string(v))
				x.unicode = nil
			}
			return
		}
		if x.escape {
			x.escape = false
			switch ch {
			case 'n':
				x.onDelta("\n")
			case 't':
				x.onDelta("\t")
			case 'r':
				x.onDelta("\r")
			case 'u':
				x.unicode = []rune{}
			default:
				x.onDelta(string(ch))
			}
			return
		}
		if ch == '\\' {
			x.escape = true
			return
		}
		if ch == '"' {
			x.state = 4
			return
		}
		x.onDelta(string(ch))
	}
}

func hexVal(r rune) rune {
	switch {
	case r >= '0' && r <= '9':
		return r - '0'
	case r >= 'a' && r <= 'f':
		return r - 'a' + 10
	case r >= 'A' && r <= 'F':
		return r - 'A' + 10
	}
	return 0
}

// AnthropicLLM calls the Messages API with the stable prefix cached and the reply streamed.
type AnthropicLLM struct {
	Client    anthropic.Client
	Model     string
	MaxTokens int64
	Effort    anthropic.OutputConfigEffort
	Timeout   time.Duration
	LastUsage anthropic.Usage
}

// NewAnthropicLLM builds the client from the environment. Returns nil when no key is set.
func NewAnthropicLLM() *AnthropicLLM {
	key := os.Getenv("ANTHROPIC_API_KEY")
	if key == "" {
		return nil
	}
	model := os.Getenv("ASSISTANT_MODEL")
	if model == "" {
		model = "claude-opus-5"
	}
	effort := anthropic.OutputConfigEffortLow
	switch os.Getenv("ASSISTANT_EFFORT") {
	case "medium":
		effort = anthropic.OutputConfigEffortMedium
	case "high":
		effort = anthropic.OutputConfigEffortHigh
	}
	return &AnthropicLLM{
		Client:    anthropic.NewClient(option.WithAPIKey(key), option.WithMaxRetries(1)),
		Model:     model,
		MaxTokens: 1200,
		Effort:    effort,
		Timeout:   25 * time.Second,
	}
}

// Call streams the reply, feeding say fragments to onDelta, and returns the whole text.
func (l *AnthropicLLM) Call(ctx context.Context, system, user string, onDelta func(string)) (string, error) {
	ctx, cancel := context.WithTimeout(ctx, l.Timeout)
	defer cancel()
	stable, volatile := SplitSystem(system)
	sys := []anthropic.TextBlockParam{{Text: stable, CacheControl: anthropic.NewCacheControlEphemeralParam()}}
	if volatile != "" {
		sys = append(sys, anthropic.TextBlockParam{Text: volatile})
	}
	params := anthropic.MessageNewParams{
		Model:        anthropic.Model(l.Model),
		MaxTokens:    l.MaxTokens,
		System:       sys,
		OutputConfig: anthropic.OutputConfigParam{Effort: l.Effort},
		Messages:     []anthropic.MessageParam{anthropic.NewUserMessage(anthropic.NewTextBlock(user))},
	}
	var extractor *SayExtractor
	if onDelta != nil {
		extractor = NewSayExtractor(onDelta)
	}
	stream := l.Client.Messages.NewStreaming(ctx, params)
	message := anthropic.Message{}
	for stream.Next() {
		ev := stream.Current()
		_ = message.Accumulate(ev)
		if extractor != nil {
			if d, ok := ev.AsAny().(anthropic.ContentBlockDeltaEvent); ok {
				if td, ok := d.Delta.AsAny().(anthropic.TextDelta); ok {
					extractor.Push(td.Text)
				}
			}
		}
	}
	if err := stream.Err(); err != nil {
		return "", err
	}
	l.LastUsage = message.Usage
	if message.StopReason == anthropic.StopReasonRefusal {
		return "", errors.New("assistant: model refused")
	}
	var b strings.Builder
	for _, block := range message.Content {
		if t, ok := block.AsAny().(anthropic.TextBlock); ok {
			b.WriteString(t.Text)
		}
	}
	return b.String(), nil
}

// SplitSystem separates the cacheable prefix from the per-turn context.
func SplitSystem(s string) (string, string) {
	i := strings.Index(s, volatileMarker)
	if i < 0 {
		return s, ""
	}
	return s[:i], s[i:]
}

// FakeLLM answers from a queue; for tests and for running without a key.
type FakeLLM struct {
	Responses []string
	Errors    []error
	Calls     []string
}

func (f *FakeLLM) Call(ctx context.Context, system, user string, onDelta func(string)) (string, error) {
	f.Calls = append(f.Calls, system+"\n---\n"+user)
	if len(f.Errors) > 0 {
		err := f.Errors[0]
		f.Errors = f.Errors[1:]
		if err != nil {
			return "", err
		}
	}
	if len(f.Responses) == 0 {
		return "", errors.New("FakeLLM: no response queued")
	}
	r := f.Responses[0]
	f.Responses = f.Responses[1:]
	if onDelta != nil {
		NewSayExtractor(onDelta).Push(r)
	}
	return r, nil
}
