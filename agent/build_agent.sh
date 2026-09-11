#!/bin/bash
#
# Build and package the netping agent Docker image (agent/Dockerfile).
#
# Always runs from the repository root so COPY agent/… in the Dockerfile
# and the tarball path htdocs/assets/netping_latest.tar.gz resolve.
# Invoke as ./agent/build_agent.sh from anywhere.
#
# Tags netping:<YYYYMMDD> and netping:latest, then gzip-saves :latest
# for the dashboard download. Build on the same architecture as the
# host that will run the container. Mixed arch (arm64 vs amd64) needs
# a build on each architecture — do not load an arm64 tarball on amd64.
#
# See agent/README.md and api-docs/agent-image.md.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

IMAGE="netping"
BUILD_DATE="$(date +%Y%m%d)"
DOCKERFILE="agent/Dockerfile"
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

log "Building Docker image..."
if docker build --tag "${IMAGE}:${BUILD_DATE}" --tag "${IMAGE}:latest" --file "${DOCKERFILE}" .; then
    success "Docker image built successfully"
else
    error "Failed to build Docker image"
    exit 1
fi

log "Current ${IMAGE} images:"
docker images "${IMAGE}"

mkdir -p "$(dirname "$ARCHIVE_NAME")"
log "Saving image to ${ARCHIVE_NAME}..."
if docker save "${IMAGE}:latest" | gzip > "${ARCHIVE_NAME}"; then
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
    docker run -d --name netping-agent --network host --restart unless-stopped \\\\
        -e SERVER=\\\"https://<SERVER>/cgi-bin/api\\\" \\\\
        -e PASSWORD=\\\"<PASSWORD>\\\" -e AGENT_ID=\\\"<AGENT_ID>\\\" \\\\
        ${IMAGE}:latest

${BLUE}To verify:${NC}
    docker ps | grep netping-agent
    docker logs netping-agent
"

success "Script completed successfully"
