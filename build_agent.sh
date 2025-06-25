#!/bin/bash
#
# Build and package Docker image for Netping Agent
#
# This script builds a Docker image, tags it with both date and latest,
# saves it to a compressed archive, and provides usage instructions.

set -euo pipefail

# Configuration
IMAGE="netping"
BUILD_DATE=$(date +%Y%m%d)
DOCKERFILE="Dockerfile.agent"
ARCHIVE_NAME="./htdocs/assets/${IMAGE}_latest.tar.gz"

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

# Build Docker image
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

# Save image to compressed archive
log "Saving image to ${ARCHIVE_NAME}..."
if docker save "${IMAGE}:latest" | gzip > "${ARCHIVE_NAME}"; then
    success "Image saved to ${ARCHIVE_NAME}"
else
    error "Failed to save image"
    exit 1
fi

# Print usage instructions
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
