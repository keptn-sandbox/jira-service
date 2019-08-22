package main

import (
	"context"
	"encoding/json"
	"fmt"
	"log"
	"os"

	cloudevents "github.com/cloudevents/sdk-go"
	"github.com/kelseyhightower/envconfig"
)

type envConfig struct {
	// Port on which to listen for cloudevents
	Port int    `envconfig:"RCV_PORT" default:"8080"`
	Path string `envconfig:"RCV_PATH" default:"/"`
}

func main() {
	var env envConfig
	if err := envconfig.Process("", &env); err != nil {
		log.Printf("[ERROR] Failed to process env var: %s", err)
		os.Exit(1)
	}
	os.Exit(_main(os.Args[1:], env))
}

type evaluationDoneEvent struct {
	Specversion     string `json:"specversion"`
	Type            string `json:"type"`
	Source          string `json:"source"`
	ID              string `json:"id"`
	Time            string `json:"time"`
	Datacontenttype string `json:"datacontenttype"`
	Shkeptncontext  string `json:"shkeptncontext"`
	Data            struct {
		Githuborg          string `json:"githuborg"`
		Project            string `json:"project"`
		Teststrategy       string `json:"teststrategy"`
		Deploymentstrategy string `json:"deploymentstrategy"`
		Stage              string `json:"stage"`
		Service            string `json:"service"`
		Image              string `json:"image"`
		Tag                string `json:"tag"`
		Evaluationpassed   bool   `json:"evaluationpassed"`
		Evaluationdetails  struct {
			Options struct {
				TimeStart int `json:"timeStart"`
				TimeEnd   int `json:"timeEnd"`
			} `json:"options"`
			TotalScore int `json:"totalScore"`
			Objectives struct {
				Pass    int `json:"pass"`
				Warning int `json:"warning"`
			} `json:"objectives"`
			// Data coming back from Prometheus sources is not strongly typed
			// especially within indicatorResults
			IndicatorResults []struct {
				ID         string `json:"id"`
				Violations []struct {
					Value interface{} `json:"value"`
					// we need to  take the key as raw json and parse it later
					Key       json.RawMessage `json:"key"`
					Breach    string          `json:"breach"`
					Threshold interface{}     `json:"threshold"`
				} `json:"violations"`
				Score int `json:"score"`
			} `json:"indicatorResults"`
			Result string `json:"result"`
		} `json:"evaluationdetails"`
	} `json:"data"`
}

func gotEvent(ctx context.Context, event cloudevents.Event) error {
	fmt.Printf("Got Event Context: %+v\n", event.Context)
	data := &evaluationDoneEvent{}
	if err := event.DataAs(data); err != nil {
		fmt.Printf("Got Data Error: %s\n", err.Error())
	}
	fmt.Printf("Got Data: %+v\n", data)

	if event.Type() != "sh.keptn.events.evaluation-done" {
		const errorMsg = "Received unexpected keptn event"
		eventType := event.Type()
		fmt.Println(eventType)
		fmt.Println(errorMsg)
	}
	if event.Type() == "sh.keptn.events.evaluation-done" {
		if data.Data.Evaluationpassed != true {
			fmt.Println("would fire jira event now")
		}
	}

	fmt.Printf("Got Transport Context: %+v\n", cloudevents.HTTPTransportContextFrom(ctx))

	fmt.Printf("----------------------------\n")

	fmt.Printf("Got Event Type: %v\n", event.Type())

	fmt.Printf("Got Event Type from struct: %v\n", data.Type)

	return nil
}

func _main(args []string, env envConfig) int {
	ctx := context.Background()

	t, err := cloudevents.NewHTTPTransport(
		cloudevents.WithPort(env.Port),
		cloudevents.WithPath(env.Path),
	)
	if err != nil {
		log.Printf("failed to create transport, %v", err)
		return 1
	}
	c, err := cloudevents.NewClient(t)
	if err != nil {
		log.Printf("failed to create client, %v", err)
		return 1
	}

	log.Printf("will listen on :%d%s\n", env.Port, env.Path)
	log.Fatalf("failed to start receiver: %s", c.StartReceiver(ctx, gotEvent))

	return 0
}
