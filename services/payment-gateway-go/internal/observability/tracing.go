package observability

import (
    "context"
    "crypto/rand"
    "encoding/hex"
    "net/http"
)

type TraceContext struct {
    TraceID  string
    SpanID   string
    ParentID string
    Sampled  bool
}

func GenerateTraceID() string {
    b := make([]byte, 16)
    rand.Read(b)
    return hex.EncodeToString(b)
}

func GenerateSpanID() string {
    b := make([]byte, 8)
    rand.Read(b)
    return hex.EncodeToString(b)
}

func NewTraceContext() TraceContext {
    return TraceContext{TraceID: GenerateTraceID(), SpanID: GenerateSpanID(), Sampled: true}
}

func (tc TraceContext) ToHeaders() map[string]string {
    return map[string]string{"traceparent": tc.ToTraceParent(), "X-Trace-ID": tc.TraceID, "X-Span-ID": tc.SpanID}
}

func (tc TraceContext) ToTraceParent() string {
    sampled := "00"
    if tc.Sampled {
        sampled = "01"
    }
    return "00-" + tc.TraceID + "-" + tc.SpanID + "-" + sampled
}

func ParseTraceParent(header string) (TraceContext, bool) {
    if len(header) != 55 {
        return TraceContext{}, false
    }
    if header[0:3] != "00-" {
        return TraceContext{}, false
    }
    traceID := header[3:35]
    spanID := header[36:52]
    flags := header[53:55]
    if _, err := hex.DecodeString(traceID); err != nil {
        return TraceContext{}, false
    }
    if _, err := hex.DecodeString(spanID); err != nil {
        return TraceContext{}, false
    }
    sampled := flags == "01"
    return TraceContext{TraceID: traceID, SpanID: GenerateSpanID(), ParentID: spanID, Sampled: sampled}, true
}

type contextKey string

const traceContextKey contextKey = "trace_context"

func WithTraceContext(ctx context.Context, tc TraceContext) context.Context {
    return context.WithValue(ctx, traceContextKey, tc)
}

func TraceFromContext(ctx context.Context) (TraceContext, bool) {
    tc, ok := ctx.Value(traceContextKey).(TraceContext)
    return tc, ok
}

func TracingMiddleware(next http.Handler) http.Handler {
    return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
        var tc TraceContext
        if traceParent := r.Header.Get("traceparent"); traceParent != "" {
            if parsed, ok := ParseTraceParent(traceParent); ok {
                tc = parsed
            } else {
                tc = NewTraceContext()
            }
        } else {
            if traceID := r.Header.Get("X-Trace-ID"); traceID != "" {
                tc = TraceContext{TraceID: traceID, SpanID: GenerateSpanID(), Sampled: true}
                if parentID := r.Header.Get("X-Span-ID"); parentID != "" {
                    tc.ParentID = parentID
                }
            } else {
                tc = NewTraceContext()
            }
        }
        ctx := WithTraceContext(r.Context(), tc)
        r = r.WithContext(ctx)
        w.Header().Set("traceparent", tc.ToTraceParent())
        w.Header().Set("X-Trace-ID", tc.TraceID)
        w.Header().Set("X-Span-ID", tc.SpanID)
        next.ServeHTTP(w, r)
    })
}

type Span struct {
    TraceID   string
    SpanID    string
    ParentID  string
    Name      string
    StartTime int64
    EndTime   int64
    Tags      map[string]interface{}
}

func StartSpan(ctx context.Context, name string) (context.Context, *Span) {
    parentTC, _ := TraceFromContext(ctx)
    span := &Span{TraceID: parentTC.TraceID, SpanID: GenerateSpanID(), ParentID: parentTC.SpanID, Name: name, Tags: make(map[string]interface{})}
    if span.TraceID == "" {
        span.TraceID = GenerateTraceID()
    }
    newTC := TraceContext{TraceID: span.TraceID, SpanID: span.SpanID, ParentID: parentTC.SpanID, Sampled: parentTC.Sampled}
    newCtx := WithTraceContext(ctx, newTC)
    return newCtx, span
}

func (s *Span) Finish() {}
func (s *Span) SetTag(key string, value interface{}) { s.Tags[key] = value }
