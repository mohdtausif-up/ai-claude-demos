package com.example.graphqldemo.config;

import graphql.GraphQLContext;
import graphql.execution.CoercedVariables;
import graphql.language.StringValue;
import graphql.language.Value;
import graphql.scalars.ExtendedScalars;
import graphql.schema.Coercing;
import graphql.schema.CoercingParseLiteralException;
import graphql.schema.CoercingParseValueException;
import graphql.schema.CoercingSerializeException;
import graphql.schema.GraphQLScalarType;
import java.util.Locale;
import java.util.regex.Pattern;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.graphql.execution.RuntimeWiringConfigurer;

@Configuration
class GraphQlConfig {

    private static final Pattern EMAIL = Pattern.compile("^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$");

    @Bean
    RuntimeWiringConfigurer runtimeWiringConfigurer() {
        return wiring -> wiring
                .scalar(ExtendedScalars.Date)
                .scalar(emailScalar())
                .directive("upper", new UpperCaseDirective());
    }

    /** Custom scalar: a String that must look like an e-mail address. */
    private static GraphQLScalarType emailScalar() {
        return GraphQLScalarType.newScalar()
                .name("Email")
                .description("A syntactically valid e-mail address")
                .coercing(new Coercing<String, String>() {
                    @Override
                    public String serialize(Object result, GraphQLContext context, Locale locale) {
                        return result.toString();
                    }

                    @Override
                    public String parseValue(Object input, GraphQLContext context, Locale locale) {
                        if (input instanceof String s && EMAIL.matcher(s).matches()) {
                            return s;
                        }
                        throw new CoercingParseValueException("Not a valid e-mail address: " + input);
                    }

                    @Override
                    public String parseLiteral(Value<?> input, CoercedVariables variables, GraphQLContext context,
                                               Locale locale) {
                        if (input instanceof StringValue s && EMAIL.matcher(s.getValue()).matches()) {
                            return s.getValue();
                        }
                        throw new CoercingParseLiteralException("Not a valid e-mail address: " + input);
                    }
                })
                .build();
    }
}
