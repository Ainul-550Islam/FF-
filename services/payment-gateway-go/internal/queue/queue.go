package queue
import ("sync"; "time")
type JobType string
const (JobPaymentVerify JobType="payment_verify"; JobWebhookProcess JobType="webhook_process"; JobRefundProcess JobType="refund_process"; JobReconciliation JobType="reconciliation"; JobSettlement JobType="settlement"; JobProviderHealth JobType="provider_health"; JobRetrySchedule JobType="retry_schedule")
type Job struct{ID string `json:"id"`; Type JobType `json:"type"`; Payload map[string]interface{} `json:"payload"`; Attempts int `json:"attempts"`; MaxAttempts int `json:"max_attempts"`; CreatedAt time.Time `json:"created_at"`; NextRetry *time.Time `json:"next_retry,omitempty"`}
type Queue struct{mu sync.RWMutex; jobs map[string]*Job}
func NewQueue() *Queue {return &Queue{jobs: make(map[string]*Job)}}
func (q *Queue) Enqueue(job *Job) error{q.mu.Lock(); defer q.mu.Unlock(); if job.ID==""{job.ID=string(job.Type)+"-"+time.Now().Format("20060102150405")}; job.CreatedAt=time.Now(); if job.MaxAttempts==0{job.MaxAttempts=3}; q.jobs[job.ID]=job; return nil}
func (q *Queue) Dequeue() (*Job, error){q.mu.Lock(); defer q.mu.Unlock(); for _,job:=range q.jobs{if job.NextRetry==nil||time.Now().After(*job.NextRetry){return job, nil}}; return nil, nil}
func (q *Queue) Complete(jobID string) error{q.mu.Lock(); defer q.mu.Unlock(); delete(q.jobs,jobID); return nil}
func (q *Queue) Fail(jobID string, err error) error{q.mu.Lock(); defer q.mu.Unlock(); job,ok:=q.jobs[jobID]; if !ok{return nil}; job.Attempts++; if job.Attempts>=job.MaxAttempts{delete(q.jobs,jobID); return nil}; backoff:=time.Duration(job.Attempts*job.Attempts)*time.Second; next:=time.Now().Add(backoff); job.NextRetry=&next; return nil}
