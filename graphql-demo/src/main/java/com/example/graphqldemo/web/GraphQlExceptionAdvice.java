package com.example.graphqldemo.web;

import graphql.GraphQLError;
import graphql.schema.DataFetchingEnvironment;
import jakarta.validation.ConstraintViolationException;
import java.util.List;
import org.springframework.graphql.data.method.annotation.GraphQlExceptionHandler;
import org.springframework.graphql.execution.ErrorType;
import org.springframework.web.bind.annotation.ControllerAdvice;

/** Maps exceptions to GraphQL errors. The rest of the response (partial data) is still returned. */
@ControllerAdvice
class GraphQlExceptionAdvice {

    @GraphQlExceptionHandler
    GraphQLError handle(NotFoundException ex, DataFetchingEnvironment env) {
        return GraphQLError.newError()
                .errorType(ErrorType.NOT_FOUND)
                .message(ex.getMessage())
                .path(env.getExecutionStepInfo().getPath())
                .location(env.getField().getSourceLocation())
                .build();
    }

    /** One BAD_REQUEST error per violated Bean Validation constraint. */
    @GraphQlExceptionHandler
    List<GraphQLError> handle(ConstraintViolationException ex, DataFetchingEnvironment env) {
        return ex.getConstraintViolations().stream()
                .map(violation -> GraphQLError.newError()
                        .errorType(ErrorType.BAD_REQUEST)
                        .message(violation.getPropertyPath() + ": " + violation.getMessage())
                        .path(env.getExecutionStepInfo().getPath())
                        .location(env.getField().getSourceLocation())
                        .build())
                .toList();
    }
}
