FROM mysql:8.0
COPY ./mysql/bibliotheca.sql /docker-entrypoint-initdb.d/bibliotheca.sql
