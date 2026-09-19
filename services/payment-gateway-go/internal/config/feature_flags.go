package config
import (
    "os"
    "strconv"
    "strings"
    "sync"
)
type FeatureFlagManager struct {
    mu sync.RWMutex
    flags map[string]bool
    critical map[string]bool
}
func NewFeatureFlagManager() *FeatureFlagManager {
    m:=&FeatureFlagManager{flags: make(map[string]bool), critical: map[string]bool{"wallet_credit":true,"audit_log":true,"settlement":true,"refund":true,"webhook_hmac_verify":true,"idempotency":true,"rate_limiting":true}}
    for _, env:=range os.Environ(){
        if strings.HasPrefix(env,"FEATURE_"){
            parts:=strings.SplitN(env,"=",2)
            if len(parts)==2{
                key:=strings.ToLower(strings.TrimPrefix(parts[0],"FEATURE_"))
                val:=parts[1]
                b,_:=strconv.ParseBool(val)
                m.flags[key]=b
            }
        }
    }
    return m
}
func (m *FeatureFlagManager) IsEnabled(flag string) bool {m.mu.RLock(); defer m.mu.RUnlock(); if v,ok:=m.flags[flag]; ok{return v}; return true}
func (m *FeatureFlagManager) IsCritical(flag string) bool {m.mu.RLock(); defer m.mu.RUnlock(); return m.critical[flag]}
func (m *FeatureFlagManager) CanDisable(flag string) bool {return !m.IsCritical(flag)}
func (m *FeatureFlagManager) SetFlag(flag string, enabled bool) error {
    m.mu.Lock(); defer m.mu.Unlock()
    if m.critical[flag]&&!enabled{return ErrCriticalFlag}
    m.flags[flag]=enabled; return nil
}
var ErrCriticalFlag = &FeatureFlagError{"cannot disable critical flag"}
type FeatureFlagError struct{msg string}
func (e *FeatureFlagError) Error() string {return e.msg}
