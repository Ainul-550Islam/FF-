package manager
import ("context"; "sync"; "github.com/ffarena/payment-gateway-go/internal/providers")
type Manager struct{mu sync.RWMutex; providers map[string]providers.Provider}
func New() *Manager {return &Manager{providers: make(map[string]providers.Provider)}}
func (m *Manager) Register(key string, p providers.Provider){m.mu.Lock(); defer m.mu.Unlock(); m.providers[key]=p}
func (m *Manager) Unregister(key string){m.mu.Lock(); defer m.mu.Unlock(); delete(m.providers,key)}
func (m *Manager) Get(key string) (providers.Provider, bool){m.mu.RLock(); defer m.mu.RUnlock(); p,ok:=m.providers[key]; return p,ok}
func (m *Manager) MustGet(key string) providers.Provider{p,ok:=m.Get(key); if !ok{panic("provider not found: "+key)}; return p}
func (m *Manager) Has(key string) bool{m.mu.RLock(); defer m.mu.RUnlock(); _,ok:=m.providers[key]; return ok}
func (m *Manager) Count() int{m.mu.RLock(); defer m.mu.RUnlock(); return len(m.providers)}
func (m *Manager) List() []string{m.mu.RLock(); defer m.mu.RUnlock(); keys:=make([]string,0,len(m.providers)); for k:=range m.providers{keys=append(keys,k)}; return keys}
func (m *Manager) SupportsCurrency(currency string) []string{m.mu.RLock(); defer m.mu.RUnlock(); var result []string; for key,p:=range m.providers{if p.SupportsCurrency(currency){result=append(result,key)}}; return result}
func (m *Manager) HealthCheck(ctx context.Context) map[string]error{m.mu.RLock(); defer m.mu.RUnlock(); results:=make(map[string]error); for key,p:=range m.providers{results[key]=p.HealthCheck(ctx)}; return results}
func (m *Manager) Metadata() map[string]map[string]interface{}{m.mu.RLock(); defer m.mu.RUnlock(); result:=make(map[string]map[string]interface{}); for key,p:=range m.providers{result[key]=p.Metadata()}; return result}
