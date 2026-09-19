package observability

import (
    "context"
    "encoding/json"
    "fmt"
    "log"
    "net/http"
    "os"
    "sync"
    "time"
)

// Tracing exporter for Jaeger/Zipkin/OTEL

type SpanExporter interface {
    Export(ctx context.Context, spans []*Span) error
}

type JaegerExporter struct {
    endpoint string
    client   *http.Client
    mu       sync.Mutex
    spans    []*Span
}

func NewJaegerExporter(endpoint string) *JaegerExporter {
    if endpoint == "" {
        endpoint = os.Getenv("JAEGER_ENDPOINT")
        if endpoint == "" {
            endpoint = os.Getenv("OTEL_EXPORTER_JAEGER_ENDPOINT")
        }
    }
    
    return &JaegerExporter{
        endpoint: endpoint,
        client: &http.Client{
            Timeout: 5 * time.Second,
        },
    }
}

func (e *JaegerExporter) Export(ctx context.Context, spans []*Span) error {
    if e.endpoint == "" {
        // No endpoint configured, log only
        return nil
    }
    
    // In production, convert to Jaeger Thrift or OTLP format and send
    // For now, just log
    for _, span := range spans {
        data, _ := json.Marshal(span)
        log.Printf("TRACE: %s", string(data))
    }
    
    return nil
}

type BatchSpanProcessor struct {
    exporter  SpanExporter
    batchSize int
    timeout   time.Duration
    mu        sync.Mutex
    spans     []*Span
    stopCh    chan struct{}
}

func NewBatchSpanProcessor(exporter SpanExporter, batchSize int, timeout time.Duration) *BatchSpanProcessor {
    if batchSize <= 0 {
        batchSize = 100
    }
    if timeout <= 0 {
        timeout = 5 * time.Second
    }
    
    b := &BatchSpanProcessor{
        exporter:  exporter,
        batchSize: batchSize,
        timeout:   timeout,
        stopCh:    make(chan struct{}),
    }
    
    go b.start()
    
    return b
}

func (b *BatchSpanProcessor) start() {
    ticker := time.NewTicker(b.timeout)
    defer ticker.Stop()
    
    for {
        select {
        case <-b.stopCh:
            b.flush()
            return
        case <-ticker.C:
            b.flush()
        }
    }
}

func (b *BatchSpanProcessor) flush() {
    b.mu.Lock()
    if len(b.spans) == 0 {
        b.mu.Unlock()
        return
    }
    
    spans := b.spans
    b.spans = []*Span{}
    b.mu.Unlock()
    
    ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
    defer cancel()
    
    if err := b.exporter.Export(ctx, spans); err != nil {
        log.Printf("Failed to export spans: %v", err)
    }
}

func (b *BatchSpanProcessor) OnEnd(span *Span) {
    b.mu.Lock()
    defer b.mu.Unlock()
    
    b.spans = append(b.spans, span)
    
    if len(b.spans) >= b.batchSize {
        go b.flush()
    }
}

func (b *BatchSpanProcessor) Shutdown(ctx context.Context) error {
    close(b.stopCh)
    b.flush()
    return nil
}

// ConsoleExporter for development
type ConsoleExporter struct{}

func NewConsoleExporter() *ConsoleExporter {
    return &ConsoleExporter{}
}

func (e *ConsoleExporter) Export(ctx context.Context, spans []*Span) error {
    for _, span := range spans {
        fmt.Printf("[TRACE] %s | TraceID: %s | SpanID: %s | Parent: %s | Tags: %v\n",
            span.Name, span.TraceID, span.SpanID, span.ParentID, span.Tags)
    }
    return nil
}

// Tracing with exporter
type Tracer struct {
    processor *BatchSpanProcessor
    serviceName string
}

func NewTracer(serviceName string) *Tracer {
    var exporter SpanExporter
    
    jaegerEndpoint := os.Getenv("JAEGER_ENDPOINT")
    if jaegerEndpoint != "" {
        exporter = NewJaegerExporter(jaegerEndpoint)
    } else if os.Getenv("APP_ENV") == "development" {
        exporter = NewConsoleExporter()
    } else {
        exporter = NewJaegerExporter("")
    }
    
    processor := NewBatchSpanProcessor(exporter, 100, 5*time.Second)
    
    return &Tracer{
        processor: processor,
        serviceName: serviceName,
    }
}

func (t *Tracer) StartSpan(ctx context.Context, name string) (context.Context, *Span) {
    parentTC, _ := TraceFromContext(ctx)
    
    span := &Span{
        TraceID: parentTC.TraceID,
        SpanID: GenerateSpanID(),
        ParentID: parentTC.SpanID,
        Name: name,
        Tags: map[string]interface{}{
            "service.name": t.serviceName,
        },
        StartTime: time.Now().UnixNano(),
    }
    
    if span.TraceID == "" {
        span.TraceID = GenerateTraceID()
    }
    
    newTC := TraceContext{
        TraceID: span.TraceID,
        SpanID: span.SpanID,
        ParentID: parentTC.SpanID,
        Sampled: parentTC.Sampled,
    }
    
    newCtx := WithTraceContext(ctx, newTC)
    return newCtx, span
}

func (t *Tracer) EndSpan(span *Span) {
    span.EndTime = time.Now().UnixNano()
    t.processor.OnEnd(span)
}
