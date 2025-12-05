#!/bin/bash

# Start Ollama container
echo "Starting Ollama container..."
docker-compose up -d

# Wait for Ollama to be ready
echo "Waiting for Ollama to start..."
sleep 5

# Pull phi4 model
echo "Pulling phi4 model (this may take a while)..."
docker exec ia-commits-ollama ollama pull phi4

echo "Ollama setup complete!"
echo "Ollama is running at: http://localhost:11434"
echo ""
echo "To test the model:"
echo "  docker exec ia-commits-ollama ollama run phi4 'Hello!'"