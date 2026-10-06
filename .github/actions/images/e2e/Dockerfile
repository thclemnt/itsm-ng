FROM mcr.microsoft.com/playwright:v1.59.1-jammy

# Reuse the native PHP runtime already owned by the Compose test harness.
COPY --from=docker:27-cli /usr/local/bin/docker /usr/local/bin/docker
COPY .github/actions/images/e2e/php /usr/local/bin/php
RUN chmod +x /usr/local/bin/php

WORKDIR /var/itsm
