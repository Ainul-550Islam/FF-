package provider_registry
import "github.com/ffarena/payment-gateway-go/internal/providers"
type Registry struct{factory *providers.Factory; providers map[string]providers.Provider}
func NewRegistry(factory *providers.Factory) *Registry {return &Registry{factory:factory,providers:make(map[string]providers.Provider)}}
func (r *Registry) Discover() error{
    for _,key:=range r.factory.SupportedProviders(){
        p,err:=r.factory.Create(key)
        if err!=nil{continue}
        r.providers[key]=p
    }
    return nil
}
func (r *Registry) Get(key string) (providers.Provider, bool){p,ok:=r.providers[key]; return p,ok}
func (r *Registry) List() []string{keys:=make([]string,0,len(r.providers)); for k:=range r.providers{keys=append(keys,k)}; return keys}
func (r *Registry) Count() int{return len(r.providers)}
