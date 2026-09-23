package observability

import (
	"context"
	"time"

	"github.com/google/uuid"
)

// The Span type and its Finish() method are declared once in tracing.go.
// This file keeps the ad-hoc helpers (NewSpan/FromContext/WithSpan) and
// builds on the exact same Span struct used by TracingMiddleware,
// StartSpan and the Jaeger exporter, so every component shares one
// representation of a span.

// NewSpan creates a standalone span outside the HTTP request lifecycle
// (request-scoped spans are created by StartSpan / Tracer.StartSpan).
func NewSpan(operation string) *Span {
	return &Span{
		TraceID:   uuid.New().String(),
		SpanID:    uuid.New().String()[:8],
		Name:      operation,
		StartTime: time.Now().UnixNano(),
		Tags:      make(map[string]interface{}),
	}
}

// FromContext returns the span previously stored by WithSpan, or a fresh
// "unknown" span when the context carries none.
func FromContext(ctx context.Context) *Span {
	if span, ok := ctx.Value("span").(*Span); ok {
		return span
	}
	return NewSpan("unknown")
}

// WithSpan stores span in ctx under the "span" key.
func WithSpan(ctx context.Context, span *Span) context.Context {
	return context.WithValue(ctx, "span", span)
}
