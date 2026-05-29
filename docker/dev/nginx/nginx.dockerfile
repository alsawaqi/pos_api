FROM nginx:1.25-alpine

# Update package lists and upgrade packages to reduce vulnerabilities
RUN apk update && apk upgrade

# Copy your custom Nginx site configuration
COPY default.conf /etc/nginx/conf.d/default.conf
