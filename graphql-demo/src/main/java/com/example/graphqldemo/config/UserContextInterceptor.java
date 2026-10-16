package com.example.graphqldemo.config;

import java.util.Map;
import org.springframework.graphql.server.WebGraphQlInterceptor;
import org.springframework.graphql.server.WebGraphQlRequest;
import org.springframework.graphql.server.WebGraphQlResponse;
import org.springframework.stereotype.Component;
import reactor.core.publisher.Mono;

/** Copies the X-User HTTP header into the GraphQL context, readable with @ContextValue in resolvers. */
@Component
class UserContextInterceptor implements WebGraphQlInterceptor {

    @Override
    public Mono<WebGraphQlResponse> intercept(WebGraphQlRequest request, Chain chain) {
        String user = request.getHeaders().getFirst("X-User");
        request.configureExecutionInput((input, builder) ->
                builder.graphQLContext(Map.of("user", user != null ? user : "anonymous")).build());
        return chain.next(request);
    }
}
