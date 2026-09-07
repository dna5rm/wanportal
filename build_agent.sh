#!/bin/bash
#
# Build and package the netping agent Docker image (Dockerfile.agent).
#
# What it does, in order:
#   1. Builds Dockerfile.agent with two tags: netping:<YYYYMMDD> and
#      netping:latest. The date tag keeps versioned images around locally;
#      the archive below is always cut from :latest.
#   2. Writes the image to htdocs/assets/netping_latest.tar.gz so the
#      dashboard can serve it as a download (the "Docker Image" card on the
#      netping page). The archive is a build artifact and gitignored.
#   3. Prints load/run instructions for the target host.
#
# Run from the repo root. See api-docs/agent-image.md for what the image contains.

set -euo pipefail

# Configuration
IMAGE="netping"                                        # base name for both tags
BUILD_DATE=$(date +%Y%m%d)                             # date tag; :latest is tagged in the same build
DOCKERFILE="Dockerfile.agent"
ARCHIVE_NAME="./htdocs/assets/${IMAGE}_latest.tar.gz"  # gitignored; served by the dashboard

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to log messages
log() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1"
}

# Function to log errors
error() {
    echo -e "${RED}[ERROR] $1${NC}" >&2
}

# Function to show success messages
success() {
    echo -e "${GREEN}[SUCCESS] $1${NC}"
}

# Main execution
log "Starting build process for ${IMAGE} image..."

# Build Docker image: one build, two tags
log "Building Docker image..."
if docker build --tag "${IMAGE}:${BUILD_DATE}" --tag "${IMAGE}:latest" --file "${DOCKERFILE}" .; then
    success "Docker image built successfully"
else
    error "Failed to build Docker image"
    exit 1
fi

# Show current images
log "Current ${IMAGE} images:"
docker images "${IMAGE}"

# Save image to compressed archive. docker save | gzip streams straight to
# the file, so no intermediate uncompressed tarball is ever written.
log "Saving image to ${ARCHIVE_NAME}..."
if docker save "${IMAGE}:latest" | gzip > "${ARCHIVE_NAME}"; then
    success "Image saved to ${ARCHIVE_NAME}"
else
    error "Failed to save image"
    exit 1
fi

# Print usage instructions. The run example uses --network host so probes
# originate from the host's own network stack, matching what the host sees.
echo -e "\n${GREEN}=== Docker Image Build Complete ===${NC}

The image has been built and saved successfully.

${BLUE}To load the image on another system:${NC}
    gunzip -c ${ARCHIVE_NAME} | docker load

${BLUE}To run the container:${NC}
    docker run -d --name netping-agent --network host --restart unless-stopped \\
        -e SERVER=\"https://<SERVER>/cgi-bin/api\" \\
        -e PASSWORD=\"<PASSWORD>\" -e AGENT_ID=\"<AGENT_ID>\" \\
        ${IMAGE}:latest

${BLUE}To verify the container is running:${NC}
    docker ps | grep netping-agent

${BLUE}To view container logs:${NC}
    docker logs netping-agent
"

success "Script completed successfully"
