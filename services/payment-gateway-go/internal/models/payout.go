package models
func (p *Payout) IsTerminal() bool {return p.Status=="completed"||p.Status=="failed"||p.Status=="cancelled"}
func (p *Payout) CanTransitionTo(newStatus string) bool {
    transitions:=map[string][]string{"pending":{"processing","failed","cancelled"},"processing":{"completed","failed","cancelled"},"completed":{}, "failed":{"pending"}, "cancelled":{}}
    allowed,ok:=transitions[p.Status]
    if !ok{return false}
    for _,s:=range allowed{if s==newStatus{return true}}
    return false
}
