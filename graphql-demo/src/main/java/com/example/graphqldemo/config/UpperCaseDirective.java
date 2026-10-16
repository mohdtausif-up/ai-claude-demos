package com.example.graphqldemo.config;

import graphql.schema.DataFetcherFactories;
import graphql.schema.GraphQLFieldDefinition;
import graphql.schema.idl.SchemaDirectiveWiring;
import graphql.schema.idl.SchemaDirectiveWiringEnvironment;

/** Implements the custom schema directive: directive @upper on FIELD_DEFINITION. */
class UpperCaseDirective implements SchemaDirectiveWiring {

    @Override
    public GraphQLFieldDefinition onField(SchemaDirectiveWiringEnvironment<GraphQLFieldDefinition> environment) {
        environment.setFieldDataFetcher(DataFetcherFactories.wrapDataFetcher(
                environment.getFieldDataFetcher(),
                (env, value) -> value instanceof String s ? s.toUpperCase() : value));
        return environment.getElement();
    }
}
