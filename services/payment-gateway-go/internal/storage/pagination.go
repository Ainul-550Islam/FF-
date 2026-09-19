package storage

type PaginationParams struct {
    Page    int `json:"page"`
    PerPage int `json:"per_page"`
    Offset  int `json:"offset"`
    Limit   int `json:"limit"`
}

func NewPaginationParams(page, perPage int) PaginationParams {
    if page < 1 {
        page = 1
    }
    if perPage < 1 {
        perPage = 50
    }
    if perPage > 100 {
        perPage = 100
    }
    return PaginationParams{
        Page:    page,
        PerPage: perPage,
        Offset:  (page - 1) * perPage,
        Limit:   perPage,
    }
}

type PaginatedResult struct {
    Data       interface{} `json:"data"`
    Page       int         `json:"page"`
    PerPage    int         `json:"per_page"`
    Total      int         `json:"total"`
    TotalPages int         `json:"total_pages"`
}
