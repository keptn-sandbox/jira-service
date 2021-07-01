package main

type DtInfoEvent struct {
	EventType string `json:"eventType"`
	Source    string `json:"source"`
	//Start            int               `json: "start,omitempty"`
	//End              int               `json: "end,omitempty"`
	AttachRules      DtAttachRules     `json:"attachRules"`
	CustomProperties map[string]string `json:"customProperties"`
	Description      string            `json:"description"`
	Title            string            `json:"title"`
}

type DtAttachRules struct {
	TagRule []DtTagRule `json:"tagRule"`
}

// DtTag defines a Dynatrace configuration structure
type DtTag struct {
	Context string `json:"context"`
	Key     string `json:"key"`
	Value   string `json:"value,omitempty"`
}

// DtTagRule defines a Dynatrace configuration structure
type DtTagRule struct {
	MeTypes []string `json:"meTypes"`
	Tags    []DtTag  `json:"tags"`
}
