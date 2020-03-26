
# JIRA Service

This service creates JIRA issues when a keptn deployment fails test evaluation.
The service is subscribed to the following keptn events:

- sh.keptn.events.evaluation-done

## Keptn Compatability

This service was tested for compatibility up through Keptn 0.5.1

## Installation

To use this service, you must have a JIRA instance accessible from your Kubernetes cluster. One can apply for a JIRA developer instance directly via Atlassian here: [http://go.atlassian.com/cloud-dev](http://go.atlassian.com/cloud-dev) 
Additionally, you must have secrets defined for the following:
* JIRA hostname
* JIRA username
* JIRA access token

you can create those secrets with the following command:

```
kubectl -n keptn create secret generic jira-service --from-literal="jira-hostname=<replacewithyourinstance>.atlassian.com" --from-literal="jira-username=<replacewithyourusername>" --from-literal="jira-token=<replacewithyouraccesstoken>"

```
Note, jira-project secret is optional. By default the jira-service will be looking for a JIRA project that is identical to the Keptn project.
If you wish to define a different JIRA project, utilize this command:

```
kubectl -n keptn create secret generic jira-service --from-literal="jira-hostname=<replacewithyourinstance>.atlassian.com" --from-literal="jira-username=<replacewithyourusername>" --from-literal="jira-token=<replacewithyouraccesstoken>" --from-literal="jira-project=<replacewithyourjiraproject>"

```

Afterwards, to install the service in your keptn installation checkout or copy the `jira-service.yaml`.

Then apply the `jira-service.yaml` using `kubectl` to create the jira service 

```
kubectl apply -f jira-service.yaml
```

Expected output:

```
deployment.apps/jira-service created
service/jira-service created
```

As of Keptn 0.4.0, an additional step is necessary to create distributors for the Keptn event channels. To install the distributors for the jira-service download or checkout the `jira-service-distributors.yaml`.

Then apply the `jira-service-distributors.yaml` using `kubectl` to create the distributors

```
kubectl apply -f jira-service-distributors.yaml
```

Expected output:

```
deployment.apps/jira-service-evaluation-done-distributor created
```

## Verification of installation

```
$ kubectl -n keptn get pod jira-service-54ddb69ff6-cg49j
NAME                            READY   STATUS    RESTARTS   AGE
jira-service-54ddb69ff6-cg49j   1/1     Running   0          65s
```

```
$ kubectl -n keptn get pod jira-service-evaluation-done-distributor-75b4dc4c57-2jvdp
NAME                                                        READY   STATUS    RESTARTS   AGE
jira-service-evaluation-done-distributor-75b4dc4c57-2jvdp   1/1     Running   0          4m3s
```

## Uninstall service

To uninstall the jira service and remove the distributors for keptn channels execute these commands.

```
kubectl delete -f jira-service-distributors.yaml
kubectl delete -f jira-service.yaml
````
