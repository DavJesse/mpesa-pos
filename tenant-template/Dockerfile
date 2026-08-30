FROM php:8.3-fpm-alpine

# Install dependencies, OpenSSH server, and PHP extensions
RUN apk add --no-cache \
    openssh \
    bash \
    supervisor \
    postgresql-dev \
    && docker-php-ext-install pdo pdo_pgsql

# Setup SSH
RUN ssh-keygen -A \
    && echo "PasswordAuthentication no" >> /etc/ssh/sshd_config \
    && echo "PubkeyAuthentication yes" >> /etc/ssh/sshd_config \
    && echo "AuthorizedKeysFile .ssh/authorized_keys" >> /etc/ssh/sshd_config \
    && adduser -D -s /bin/bash tenant \
    && chown -R tenant:tenant /var/www/html

# Create SSH directory for tenant user
RUN mkdir -p /home/tenant/.ssh && \
    chmod 700 /home/tenant/.ssh && \
    chown -R tenant:tenant /home/tenant/.ssh

# Setup Supervisor to run both PHP-FPM and SSHd
COPY supervisord.conf /etc/supervisord.conf

WORKDIR /var/www/html

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
