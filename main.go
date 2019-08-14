package main

import (
	"encoding/json"
	"fmt"
	"io"
	"io/ioutil"
	"log"
	"net/http"
	"os"
	"strconv"

	"github.com/andygrunwald/go-jira"
)

type keptnEvent struct {
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

var ufoRow string

//Logging : sets up info and error logging
func Logging(infoLogger io.Writer, errorLogger io.Writer) {
	infoLog = log.New(infoLogger, "INFO: ", log.Ldate|log.Ltime|log.Lshortfile)
	errorLog = log.New(errorLogger, "ERROR: ", log.Ldate|log.Ltime|log.Lshortfile)
}

//keptnHandler : receives keptn events via http and sets UFO LEDs based on payload
func keptnHandler(w http.ResponseWriter, r *http.Request) {
	decoder := json.NewDecoder(r.Body)
	var event keptnEvent
	err := decoder.Decode(&event)
	if err != nil {
		fmt.Println("Error while parsing JSON payload: " + err.Error())
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

	if event.Type == "sh.keptn.events.evaluation-done" {
		if event.Data.Evaluationpassed != true {
			infoLog.Println("Trying to talk to JIRA at " + jiraHostname)
			postJIRAIssue(jiraHostname, event)
		}
	}
}

func postJIRAIssue(jiraHostname string, event keptnEvent) {
	var strViolationsValue string
	var strKey string
	var strValThreshold string
	var keyDT string
	var keyProm PrometheusKey
	var indicatorValues string
	url := "https://" + jiraHostname

	//	fmt.Println("URL to be used: " + url)

	// iterating through the contents of IndicatorResults so they can be sent to JIRA
	for i := 0; i < len(event.Data.Evaluationdetails.IndicatorResults); i++ {
		for v := 0; v < len(event.Data.Evaluationdetails.IndicatorResults[i].Violations); v++ {

			valDouble, ok := event.Data.Evaluationdetails.IndicatorResults[i].Violations[v].Value.(float64)
			if ok {
				strViolationsValue = fmt.Sprintf("%f", valDouble)
			}
			valBoolean, ok := event.Data.Evaluationdetails.IndicatorResults[i].Violations[v].Value.(bool)
			if ok {
				strViolationsValue = fmt.Sprintf("%t", valBoolean)
			}
			valString, ok := event.Data.Evaluationdetails.IndicatorResults[i].Violations[v].Value.(string)
			if ok {
				strViolationsValue = valString
			}
			// threshold might not exist and should be a float64, if it is a string this will say it isn't there...
			valThreshold, ok := event.Data.Evaluationdetails.IndicatorResults[i].Violations[v].Threshold.(float64)
			if ok {
				strValThreshold = strconv.FormatFloat(valThreshold, 'f', -1, 64)
			} else {
				strValThreshold = "No Threshold in Pitometer response"
			}

			if err := json.Unmarshal(event.Data.Evaluationdetails.IndicatorResults[i].Violations[v].Key, &keyDT); err == nil {
				strKey = keyDT
			}
			// Prometheus Key is an object containing job and an instance, we will use instance as it is more verbose

			if err := json.Unmarshal(event.Data.Evaluationdetails.IndicatorResults[i].Violations[v].Key, &keyProm); err == nil {
				strKey = keyProm.Instance
			}
			indicatorValues = indicatorValues +
				"\nIndicator ID: " + event.Data.Evaluationdetails.IndicatorResults[i].ID +
				"\nIndicator Key: " + strKey +
				"\nIndicator Value: " + strViolationsValue +
				"\nIndicator Threshold: " + strValThreshold +
				"\nIndicator Breach: " + event.Data.Evaluationdetails.IndicatorResults[i].Violations[v].Breach
		}
	}

	jiraIssue := "Keptn test evaluation failed, build was not deployed" +
		"\nshkeptncontext: " + event.Shkeptncontext +
		"\nFailed stage: " + event.Data.Stage +
		"\nFailed service: " + event.Data.Service +
		"\nGithuborg: " + event.Data.Githuborg +
		"\nTotal score: " + strconv.Itoa(event.Data.Evaluationdetails.TotalScore) +
		"\nPass Threshold: " + strconv.Itoa(event.Data.Evaluationdetails.Objectives.Pass) +
		"\nWarning Threshold: " + strconv.Itoa(event.Data.Evaluationdetails.Objectives.Warning) +
		indicatorValues +
		"\nOverall Result: " + event.Data.Evaluationdetails.Result

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
				Key: "SOCKSHOP",
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

	http.HandleFunc("/", keptnHandler)

	port := os.Getenv("PORT")
	if port == "" {
		port = "8080"
	}

	log.Print("JIRA service started.")
	log.Fatal(http.ListenAndServe(fmt.Sprintf(":%s", port), nil))
}
