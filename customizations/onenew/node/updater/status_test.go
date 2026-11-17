package main

import "net/http"

type httpHandler409 struct{}

func (httpHandler409) ServeHTTP(w http.ResponseWriter, r *http.Request) { w.WriteHeader(409) }
