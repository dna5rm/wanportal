#!/bin/bash
#
# Build and package the netping agent Docker image (agent/Dockerfile).
#
# Always runs from the repository root so COPY agent/… in the Dockerfile
# and the tarball path htdocs/assets/netping_latest.tar.gz resolve.
# Invoke as ./agent/build_agent.sh from anywhere.
#
# Tags netping:<agent version> (read from agent/netping-agent.pl),
# netping:<YYYYMMDD> and netping:latest, then gzip-saves the versioned
# tag together with :latest for the dashboard download. Build on the
# same architecture as the host that will run the container. Mixed arch
# (arm64 vs amd64) needs a build on each architecture — do not load an
# arm64 tarball on amd64.
#
# See agent/README.md and api-docs/agent-image.md.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

IMAGE="netping"
BUILD_DATE="$(date +%Y%m%d)"
DOCKERFILE="agent/Dockerfile"
AGENT_SRC="agent/netping-agent.pl"
ARCHIVE_NAME="./htdocs/assets/${IMAGE}_latest.tar.gz"

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

log() { echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1"; }
error() { echo -e "${RED}[ERROR] $1${NC}" >&2; }
success() { echo -e "${GREEN}[SUCCESS] $1${NC}"; }

ARCH="$(uname -m)"
log "Starting build for ${IMAGE} (host arch ${ARCH})..."
if [ "$ARCH" = "aarch64" ] || [ "$ARCH" = "arm64" ]; then
    log "This image will be linux/arm64. Do not docker-load it on amd64. Build on the target host instead."
fi

if [ ! -f "$DOCKERFILE" ]; then
    error "missing $DOCKERFILE (cwd=$ROOT)"
    exit 1
fi

if [ ! -f "$AGENT_SRC" ]; then
    error "missing $AGENT_SRC (cwd=$ROOT) — cannot determine the agent version"
    exit 1
fi

# The version tag must match what the agent reports to the portal, so
# read it from the shipped script (agent/netping-agent.pl):
#     our $VERSION = '0.2.0';
# instead of maintaining a second copy of the number here.
AGENT_VERSION="$(sed -n 's/^[[:space:]]*our \$VERSION[[:space:]]*=[[:space:]]*//p' "$AGENT_SRC" | head -n1 | tr -d "\"';[:space:]")"
if [ -z "$AGENT_VERSION" ]; then
    error "could not read the agent version from $AGENT_SRC"
    error "expected a line like: our \$VERSION = '0.2.0';"
    exit 1
fi
if ! printf '%s' "$AGENT_VERSION" | grep -qE '^[0-9][0-9A-Za-z._-]*$'; then
    error "agent version extracted from $AGENT_SRC looks invalid: '${AGENT_VERSION}'"
    exit 1
fi
log "Agent version: ${AGENT_VERSION} -> tagging ${IMAGE}:${AGENT_VERSION}"

log "Building Docker image..."
if docker build \
    --tag "${IMAGE}:${AGENT_VERSION}" \
    --tag "${IMAGE}:${BUILD_DATE}" \
    --tag "${IMAGE}:latest" \
    --file "${DOCKERFILE}" .; then
    success "Docker image built successfully"
else
    error "Failed to build Docker image"
    exit 1
fi

log "Current ${IMAGE} images:"
docker images "${IMAGE}"

mkdir -p "$(dirname "$ARCHIVE_NAME")"
log "Saving image to ${ARCHIVE_NAME} (tags: ${IMAGE}:${AGENT_VERSION} + ${IMAGE}:latest)..."
if docker save "${IMAGE}:${AGENT_VERSION}" "${IMAGE}:latest" | gzip > "${ARCHIVE_NAME}"; then
    success "Image saved to ${ARCHIVE_NAME}"
else
    error "Failed to save image"
    exit 1
fi

echo -e "
${GREEN}=== Docker Image Build Complete ===${NC}

${BLUE}To load the image on another system of the SAME architecture:${NC}
    gunzip -c ${ARCHIVE_NAME} | docker load

${BLUE}To run the container:${NC}
# PASSWORD is single-quoted on purpose - agent passwords are random and may
# contain shell metacharacters ($ etc.) that double quotes would expand.
    docker run -d --name netping-agent --network host --restart unless-stopped \\\\
        -e SERVER=\"https://<SERVER>/cgi-bin/api\" \\\\
        -e PASSWORD='<PASSWORD>' -e AGENT_ID=\"<AGENT_ID>\" \\\\
        ${IMAGE}:${AGENT_VERSION}

${BLUE}To verify:${NC}
    docker ps | grep netping-agent
    docker logs netping-agent
"

success "Script completed successfully"
