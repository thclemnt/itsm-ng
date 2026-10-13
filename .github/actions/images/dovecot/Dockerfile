FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
    dovecot-core \
    dovecot-imapd \
    dovecot-pop3d \
 && rm -rf /var/lib/apt/lists/*

RUN useradd --create-home --home-dir /home/testuser --shell /bin/bash testuser \
 && echo 'testuser:applesauce' | chpasswd \
 && install -d -o testuser -g testuser \
    /home/testuser/Maildir \
    /home/testuser/Maildir/cur /home/testuser/Maildir/new /home/testuser/Maildir/tmp

RUN sed -ri 's|^#?listen =.*$|listen = *, ::|' /etc/dovecot/dovecot.conf \
 && sed -ri 's|^#?mail_location =.*$|mail_location = maildir:~/Maildir|' /etc/dovecot/conf.d/10-mail.conf \
 && sed -ri 's|^#?disable_plaintext_auth =.*$|disable_plaintext_auth = no|' /etc/dovecot/conf.d/10-auth.conf \
 && sed -ri 's|^auth_mechanisms =.*$|auth_mechanisms = plain login|' /etc/dovecot/conf.d/10-auth.conf \
 && sed -ri 's|^ssl =.*$|ssl = no|' /etc/dovecot/conf.d/10-ssl.conf

EXPOSE 143 110

CMD ["dovecot", "-F"]
