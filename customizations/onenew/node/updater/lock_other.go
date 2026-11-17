//go:build !linux

package main

import "errors"

func lock(path string) (func(), error) { return nil, errors.New("Linux service required") }
