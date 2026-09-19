package security
import ("errors"; "time"; "github.com/golang-jwt/jwt/v5")
type Claims struct{UserID int64 `json:"user_id"`; ServiceID string `json:"service_id"`; jwt.RegisteredClaims}
func GenerateJWT(secret string, userID int64, serviceID string, expiry time.Duration) (string, error){
    claims:=Claims{UserID:userID,ServiceID:serviceID,RegisteredClaims:jwt.RegisteredClaims{ExpiresAt:jwt.NewNumericDate(time.Now().Add(expiry)),IssuedAt:jwt.NewNumericDate(time.Now()),Issuer:serviceID}}
    token:=jwt.NewWithClaims(jwt.SigningMethodHS256,claims)
    return token.SignedString([]byte(secret))
}
func VerifyJWT(tokenString, secret string) (*Claims, error){
    token,err:=jwt.ParseWithClaims(tokenString,&Claims{},func(token *jwt.Token) (interface{}, error){return []byte(secret), nil})
    if err!=nil{return nil, err}
    if claims,ok:=token.Claims.(*Claims); ok&&token.Valid{return claims, nil}
    return nil, errors.New("invalid token")
}
