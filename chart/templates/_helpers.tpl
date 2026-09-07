{{/* Return the chart name, honoring nameOverride. */}}
{{- define "wanportal.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/* Return the fully qualified app name, honoring fullnameOverride. */}}
{{- define "wanportal.fullname" -}}
{{- if .Values.fullnameOverride }}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- $name := default .Chart.Name .Values.nameOverride }}
{{- if contains $name .Release.Name }}
{{- .Release.Name | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" }}
{{- end }}
{{- end }}
{{- end }}

{{/* Chart name and version, e.g. wanportal-1.0.0. */}}
{{- define "wanportal.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/* Common labels. */}}
{{- define "wanportal.labels" -}}
helm.sh/chart: {{ include "wanportal.chart" . }}
{{ include "wanportal.selectorLabels" . }}
{{- if .Chart.AppVersion }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
{{- end }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end }}

{{/* Selector labels. */}}
{{- define "wanportal.selectorLabels" -}}
app.kubernetes.io/name: {{ include "wanportal.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end }}

{{/* Container image reference: repository plus tag (default appVersion). */}}
{{- define "wanportal.image" -}}
{{- printf "%s:%s" .Values.image.repository (default .Chart.AppVersion .Values.image.tag) -}}
{{- end }}

{{/* Secret holding the MYSQL/JWT/APP (+LDAP) credential keys. */}}
{{- define "wanportal.secretName" -}}
{{- default (include "wanportal.fullname" .) .Values.secrets.existingSecret -}}
{{- end }}
