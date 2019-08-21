package main

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"io/ioutil"
	"log"
	"os"
	"strconv"
	"strings"

	"github.com/andygrunwald/go-jira"
	cloudevents "github.com/cloudevents/sdk-go"
)

// EvaluationDoneEvent payload for evaluation done event, see keptn spec
type EvaluationDoneEvent struct {
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
}

// PrometheusKey is a json object containing job and an instance, we will use instance as it is more verbose
type PrometheusKey struct {
	Instance string `json:"instance"`
	Job      string `json:"job"`
}

var (
	infoLog  *log.Logger
	errorLog *log.Logger
)

var jiraHostname string
var jiraUsername string
var jiraToken string
var jiraProject string
var ufoRow string

//Logging : sets up info and error logging
func Logging(infoLogger io.Writer, errorLogger io.Writer) {
	infoLog = log.New(infoLogger, "INFO: ", log.Ldate|log.Ltime|log.Lshortfile)
	errorLog = log.New(errorLogger, "ERROR: ", log.Ldate|log.Ltime|log.Lshortfile)
}

//keptnHandler : receives keptn events via http and creates JIRA tickets for failed evaluations
func keptnHandler(event cloudevents.Event) {
	var shkeptncontext string
	event.Context.ExtensionAs("shkeptncontext", &shkeptncontext)

	data := &EvaluationDoneEvent{}
	if err := event.DataAs(data); err != nil {
		fmt.Printf("Got Data Error: %s", err.Error())
		return
	}
	jiraHostname = os.Getenv("JIRA_HOSTNAME")
	if jiraHostname == "" {
		errorLog.Println("No JIRA hostname defined")
		return
	}
	jiraUsername = os.Getenv("JIRA_USERNAME")
	if jiraUsername == "" {
		errorLog.Println("No JIRA username defined")
		return
	}
	jiraToken = os.Getenv("JIRA_TOKEN")
	if jiraToken == "" {
		errorLog.Println("No JIRA token defined")
		return
	}

	jiraProject = os.Getenv("JIRA_PROJECT")
	if jiraProject == "" {
		jiraProject = strings.ToUpper(data.Project)
	}

	if event.Type() == "sh.keptn.events.evaluation-done" {
		if data.Evaluationpassed != true {
			infoLog.Println("Trying to talk to JIRA at " + jiraHostname)
			infoLog.Println("using JIRA project " + jiraProject)
			postJIRAIssue(jiraHostname, *data)
		}
	}
}

func postJIRAIssue(jiraHostname string, data EvaluationDoneEvent) {
	var strViolationsValue string
	var strKey string
	var strValThreshold string
	var keyDT string
	var keyProm PrometheusKey
	var indicatorValues string
	url := "https://" + jiraHostname

	//	fmt.Println("URL to be used: " + url)

	// iterating through the contents of IndicatorResults so they can be sent to JIRA
	for i := 0; i < len(data.Evaluationdetails.IndicatorResults); i++ {
		for v := 0; v < len(data.Evaluationdetails.IndicatorResults[i].Violations); v++ {

			valDouble, ok := data.Evaluationdetails.IndicatorResults[i].Violations[v].Value.(float64)
			if ok {
				strViolationsValue = fmt.Sprintf("%f", valDouble)
			}
			valBoolean, ok := data.Evaluationdetails.IndicatorResults[i].Violations[v].Value.(bool)
			if ok {
				strViolationsValue = fmt.Sprintf("%t", valBoolean)
			}
			valString, ok := data.Evaluationdetails.IndicatorResults[i].Violations[v].Value.(string)
			if ok {
				strViolationsValue = valString
			}
			// threshold might not exist and should be a float64, if it is a string this will say it isn't there...
			valThreshold, ok := data.Evaluationdetails.IndicatorResults[i].Violations[v].Threshold.(float64)
			if ok {
				strValThreshold = strconv.FormatFloat(valThreshold, 'f', -1, 64)
			} else {
				strValThreshold = "No Threshold in Pitometer response"
			}

			if err := json.Unmarshal(data.Evaluationdetails.IndicatorResults[i].Violations[v].Key, &keyDT); err == nil {
				strKey = keyDT
			}
			// Prometheus Key is an object containing job and an instance, we will use instance as it is more verbose

			if err := json.Unmarshal(data.Evaluationdetails.IndicatorResults[i].Violations[v].Key, &keyProm); err == nil {
				strKey = keyProm.Instance
			}
			indicatorValues = indicatorValues +
				"\nIndicator ID: " + data.Evaluationdetails.IndicatorResults[i].ID +
				"\nIndicator Key: " + strKey +
				"\nIndicator Value: " + strViolationsValue +
				"\nIndicator Threshold: " + strValThreshold +
				"\nIndicator Breach: " + data.Evaluationdetails.IndicatorResults[i].Violations[v].Breach
		}
	}

	jiraIssue := "Keptn test evaluation failed, build was not deployed" +
		//"\nshkeptncontext: " + event.Shkeptncontext +
		"\nFailed stage: " + data.Stage +
		"\nFailed service: " + data.Service +
		"\nGithuborg: " + data.Githuborg +
		"\nTotal score: " + strconv.Itoa(data.Evaluationdetails.TotalScore) +
		"\nPass Threshold: " + strconv.Itoa(data.Evaluationdetails.Objectives.Pass) +
		"\nWarning Threshold: " + strconv.Itoa(data.Evaluationdetails.Objectives.Warning) +
		indicatorValues +
		"\nOverall Result: " + data.Evaluationdetails.Result

	tp := jira.BasicAuthTransport{
		Username: jiraUsername,
		Password: jiraToken,
	}

	jiraClient, err := jira.NewClient(tp.Client(), url)
	if err != nil {
		panic(err)
	}

	//	fmt.Printf("Issue Text %s\n", jiraIssue)

	i := jira.Issue{
		Fields: &jira.IssueFields{
			Assignee: &jira.User{
				Name: "admin",
			},
			Reporter: &jira.User{
				Name: "admin",
			},
			Description: jiraIssue,
			Type: jira.IssueType{
				Name: "Bug",
			},
			Project: jira.Project{
				Key: jiraProject,
			},
			Summary: "Keptn Test Evaluation Failed",
		},
	}
	issue, response, err := jiraClient.Issue.Create(&i)

	if err != nil {
		// all this stuff is necessary to get back the response from JIRA if there is an error
		bodyBytes, _ := ioutil.ReadAll(response.Response.Body)
		bodyString := string(bodyBytes)
		fmt.Printf("%s\n", bodyString)
		panic(err)
	}
	fmt.Printf("Key:%s, ID:%+v\n", issue.Key, issue.ID)

}

func main() {
	Logging(os.Stdout, os.Stderr)

	Port, err := strconv.Atoi(os.Getenv("PORT"))
	if Port == 0 {
		Port = 8080
	}
	Path := os.Getenv("PATH")
	if Path == "" {
		Path = "/"
	}
	t, err := cloudevents.NewHTTPTransport(
		cloudevents.WithPort(Port),
		cloudevents.WithPath(Path),
	)
	if err != nil {
		log.Fatalf("failed to create transport, %v", err)
	}

	log.Print("JIRA service started.")

	c, err := cloudevents.NewClient(t)
	if err != nil {
		log.Fatalf("failed to create client, %v", err)
	}
	log.Fatal(c.StartReceiver(context.Background(), keptnHandler))
}
