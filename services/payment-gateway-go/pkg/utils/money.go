package utils

import "fmt"

func ToMinor(major float64) int64 {
    return int64(major * 100)
}

func ToMajor(minor int64) float64 {
    return float64(minor) / 100
}

func FormatBDT(minor int64) string {
    major := float64(minor) / 100.0
    return fmt.Sprintf("BDT %.2f", major)
}

func FormatCurrency(minor int64, currency string) string {
    major := float64(minor) / 100.0
    return fmt.Sprintf("%s %.2f", currency, major)
}

func ParseBDT(bdtString string) (int64, error) {
    var major float64
    _, err := fmt.Sscanf(bdtString, "BDT %f", &major)
    if err != nil {
        return 0, err
    }
    return ToMinor(major), nil
}
