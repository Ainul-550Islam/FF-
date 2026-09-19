package observability
import ("context"; "github.com/google/uuid")
type Span struct{TraceID string `json:"trace_id"`; SpanID string `json:"span_id"`; Operation string `json:"operation"`}
func NewSpan(operation string) *Span {return &Span{TraceID:uuid.New().String(),SpanID:uuid.New().String()[:8],Operation:operation}}
func (s *Span) Finish(){}
func FromContext(ctx context.Context) *Span{if span,ok:=ctx.Value("span").(*Span); ok{return span}; return NewSpan("unknown")}
func WithSpan(ctx context.Context, span *Span) context.Context {return context.WithValue(ctx,"span",span)}
