
# JIRA Service

This service creates issues when a keptn deployment fails test evaluation.
The service is subscribed to the following keptn events:

- sh.keptn.events.evaluation-done

An evaluation-done event will set LEDs based on the status of the evaluation. Pass will set LEDs to GREEN, Fail will set LEDs to RED (ff0000)
In the event of a failed evaluation, the JIRA service will create an event in the configured project against the configured JIRA environment

## Installation

To use this service, you must have a JIRA instance accessible from your Kubernetes cluster. Additionally, you must have a secret defined with the hostname of your JIRA instance:
```
kubectl -n keptn create secret generic jira --from-literal="JIRA_ADDRESS=<replacewithyourinstance>.atlassian.com"
```

Afterwards, to install the service in your keptn installation checkout or copy the `jira-service.yaml`.

Then apply the `jira-service.yaml` using `kubectl` to create the Dynatrace service and the subscriptions to the keptn channels.

```
kubectl apply -f jira-service.yaml
```

Expected output:

```
service.serving.knative.dev/jira-service created
subscription.eventing.knative.dev/jira-subscription-evaluation-done created
```

## Verification of installation

```
$ kubectl get ksvc jira-service -n keptn
NAME            DOMAIN                               LATESTCREATED         LATESTREADY           READY     REASON
jira-service   jira-service.keptn.x.x.x.x.xip.io   jira-service-dd9km   jira-service-dd9km   True
```

```
$ kubectl get subscription -n keptn | grep jira-subscription
jira-subscription-evaluation-done              True
$
```

In the event of a failed evaluation, a JIRA issue will be created.

## Uninstall service

To uninstall the dynatrace service and remove the subscriptions to keptn channels execute this command.

```
kubectl delete -f jira-service.yaml
````