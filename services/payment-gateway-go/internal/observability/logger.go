package observability
import ("encoding/json"; "log"; "strings")
type Logger struct{service string; env string; version string}
func NewLogger(service, env, version string) *Logger {return &Logger{service:service,env:env,version:version}}
func (l *Logger) Info(msg string, fields map[string]interface{}){l.log("info",msg,fields)}
func (l *Logger) Error(msg string, fields map[string]interface{}){l.log("error",msg,fields)}
func (l *Logger) Audit(action string, fields map[string]interface{}){fields["action"]=action; l.log("audit",action,fields)}
func (l *Logger) log(level, msg string, fields map[string]interface{}){
    if fields==nil{fields=make(map[string]interface{})}
    fields["level"]=level; fields["service"]=l.service; fields["env"]=l.env; fields["version"]=l.version; fields["message"]=l.redact(msg)
    for k,v:=range fields{if isSensitive(k){fields[k]="***REDACTED***"} else if s,ok:=v.(string); ok&&isSensitiveValue(s){fields[k]="***REDACTED***"}}
    b,_:=json.Marshal(fields); log.Println(string(b))
}
func (l *Logger) redact(s string) string{
    sensitive:=[]string{"password","secret","token","jwt","DATABASE_URL","REDIS_URL"}
    for _,key:=range sensitive{if strings.Contains(strings.ToLower(s),strings.ToLower(key)){return "***REDACTED***"}}
    return s
}
func (l *Logger) WithRequestID(requestID string) *Logger {return l}
func isSensitive(key string) bool{
    lower:=strings.ToLower(key)
    sensitive:=[]string{"password","secret","token","jwt","api_key","private_key","database_url","redis_url"}
    for _,s:=range sensitive{if strings.Contains(lower,s){return true}}
    return false
}
func isSensitiveValue(v string) bool{if len(v)>20&&(strings.HasPrefix(v,"eyJ")||strings.HasPrefix(v,"sk_")){return true}; return false}
