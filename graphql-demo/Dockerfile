# The jar is built on the host first (mvn package), so the image build needs no Maven downloads.
# This avoids TLS-interception problems behind corporate proxies.
FROM eclipse-temurin:17-jre
COPY target/graphql-demo-*.jar /app.jar
EXPOSE 8080
ENTRYPOINT ["java", "-jar", "/app.jar"]
