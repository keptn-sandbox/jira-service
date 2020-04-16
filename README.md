# JIRA Service

This service creates JIRA issues when Keptn performs a quality gate evaluation.

The service is subscribed to the following keptn events:

* `sh.keptn.events.evaluation-done`

![screenshot](assets/jira-service-1.png)

# Gather JIRA Information
You'll need the following information to use this plugin.

1. JIRA hostname  (without trailing slash) such as `https://myusername.atlassian.net`
1. JIRA username such as `me@example.com`
1. JIRA API Token ([generate one here](https://id.atlassian.com/manage/api-tokens))
1. JIRA Project Code. Take this from the URL. Eg. `PROJ` is the project code for `https://myusername.atlassian.net/projects/PROJ/issues`

# Save JIRA Details as k8s Secret
Paste your values into the command below (replacing `***`) and save the JIRA details into a secret called `jira-details` in the `keptn` namespace.

```
kubectl -n keptn create secret generic jira-details --from-literal="jira-hostname=***" --from-literal="jira-username=***" --from-literal="jira-token=***" --from-literal="jira-project=***"
```

Expected output:

```
secret/jira-details created
```

# Install JIRA Service
Clone this repository and apply the `jira-service.yaml` and `jira-distributor.yaml` file to install the service on to keptn.

```
kubectl apply -f ~/jira-service/jira-distributor.yaml -f ~/jira-service/jira-service.yaml
```

Expected output:

```
deployment.apps/jira-service-distributor created
deployment.apps/jira-service created
service/jira-service created
```

# Debugging
A debug log is available in the `jira-service` pod at `/var/www/html/logs/jiraService.log`

```
kubectl exec -itn keptn jira-service-*-* cat /var/www/html/logs/jiraService.log
```

# Compatibility Matrix

| Keptn Version    | JIRA Version / API Version |
|:----------------:|:----------------------:|
|     0.6.1        |            Cloud / v2          |

# Contributions, Enhancements, Issues or Questions
Please raise a GitHub issue or join the [Keptn Slack channel](https://join.slack.com/t/keptn/shared_invite/enQtNTUxMTQ1MzgzMzUxLWMzNmM1NDc4MmE0MmQ0MDgwYzMzMDc4NjM5ODk0ZmFjNTE2YzlkMGE4NGU5MWUxODY1NTBjNjNmNmI1NWQ1NGY).