package observability
import "sync"
type Metrics interface{Increment(name string, tags map[string]string); Gauge(name string, value float64, tags map[string]string); Timing(name string, durationMs int64, tags map[string]string); GetCounter(name string) int64; AllCounters() map[string]int64}
type NullMetrics struct{}
func (n *NullMetrics) Increment(name string, tags map[string]string){}
func (n *NullMetrics) Gauge(name string, value float64, tags map[string]string){}
func (n *NullMetrics) Timing(name string, durationMs int64, tags map[string]string){}
func (n *NullMetrics) GetCounter(name string) int64 {return 0}
func (n *NullMetrics) AllCounters() map[string]int64 {return map[string]int64{}}
type InMemoryMetrics struct{mu sync.RWMutex; counters map[string]int64; gauges map[string]float64; timings map[string][]int64}
func NewInMemoryMetrics() *InMemoryMetrics {return &InMemoryMetrics{counters: make(map[string]int64), gauges: make(map[string]float64), timings: make(map[string][]int64)}}
func (m *InMemoryMetrics) Increment(name string, tags map[string]string){m.mu.Lock(); defer m.mu.Unlock(); m.counters[name]++}
func (m *InMemoryMetrics) Gauge(name string, value float64, tags map[string]string){m.mu.Lock(); defer m.mu.Unlock(); m.gauges[name]=value}
func (m *InMemoryMetrics) Timing(name string, durationMs int64, tags map[string]string){m.mu.Lock(); defer m.mu.Unlock(); m.timings[name]=append(m.timings[name],durationMs)}
func (m *InMemoryMetrics) GetCounter(name string) int64{m.mu.RLock(); defer m.mu.RUnlock(); return m.counters[name]}
func (m *InMemoryMetrics) AllCounters() map[string]int64{m.mu.RLock(); defer m.mu.RUnlock(); result:=make(map[string]int64); for k,v:=range m.counters{result[k]=v}; return result}
