# GraphQL demo (Spring Boot 4.1 · Java 17 · PostgreSQL in Docker)

A small "library" API (authors, books, reviews) that shows the GraphQL features one by one.

## Run

Requirements: JDK 17+, Maven, Docker.

```bash
mvn spring-boot:run
```

`spring-boot-docker-compose` starts the PostgreSQL container from `compose.yaml` automatically; no manual DB setup.
Data is re-seeded on every start (`ddl-auto: create`).

Or run everything in Docker (build the jar on the host first, the image only copies it):
```bash
mvn package -DskipTests
docker compose --profile app up --build
```

| URL | What |
|---|---|
| http://localhost:8080/graphiql | GraphiQL UI (docs explorer, autocomplete, subscriptions) |
| http://localhost:8080/graphql | endpoint: HTTP POST for queries/mutations, SSE or WebSocket for subscriptions |
| http://localhost:8080/graphql/schema | schema as text (SDL) |

## Where each feature lives

| Feature | Where |
|---|---|
| Schema-first SDL, descriptions | `src/main/resources/graphql/schema.graphqls` |
| Queries, arguments, default values | `QueryController` |
| Mutations + input types | `MutationController`, `model/Inputs.java` |
| Subscriptions | `SubscriptionController`, `LibraryEvents` |
| Enums, interface (`Node`), union (`SearchResult`) | schema; types resolved from Java class names |
| Custom scalars (`Date`, `Email`) | `GraphQlConfig` |
| Custom directive (`@upper`) | `UpperCaseDirective` |
| `@deprecated`, introspection | schema (`Book.legacyCode`), `__schema` / `__type` queries |
| DataLoader / batching (no N+1) | `FieldResolvers` (`@BatchMapping`) |
| Relay cursor pagination | `QueryController.booksConnection` |
| Validation (`BAD_REQUEST`) and error mapping (`NOT_FOUND`) | `Inputs`, `GraphQlExceptionAdvice` |
| Request context from HTTP header | `UserContextInterceptor` + `Query.whoami` |

## Try it (paste into GraphiQL)

**Query, aliases, arguments, default values**
```graphql
{
  greeting: hello
  custom: hello(name: "GraphQL")
  dune: book(id: 5) { title pages }
}
```

**Variables, fragments, `@include` / `@skip`, operation name** (variables: `{"withReviews": true, "genre": "SCIENCE_FICTION"}`)
```graphql
query SciFi($genre: Genre, $withReviews: Boolean!) {
  books(filter: { genre: $genre }, orderBy: PUBLISHED) {
    ...bookFields
    reviews @include(if: $withReviews) { rating comment }
    legacyCode @skip(if: $withReviews)
  }
}
fragment bookFields on Book { id title published author { name } }
```

**Nested data + DataLoader** (watch the SQL log: one query per level, not per row)
```graphql
{ authors { shoutName email born books { title reviews { rating } } } }
```

**Interface / union with inline fragments and `__typename`**
```graphql
{
  search(text: "ar") {
    __typename
    ... on Author { name }
    ... on Book { title }
    ... on Review { comment }
  }
}
```

**Pagination (Relay connection)**: pass `endCursor` as `after` for the next page
```graphql
{ booksConnection(first: 2) { edges { cursor node { title } } pageInfo { hasNextPage endCursor } } }
```

**Mutation with input type**
```graphql
mutation {
  addAuthor(input: { name: "N. K. Jemisin", email: "nk@example.com", born: "1972-09-19" }) { id name }
}
```

**Subscription** (open it in one GraphiQL tab, then run the mutation in another)
```graphql
subscription { bookAdded(genre: TECH) { id title author { name } } }
```
```graphql
mutation { addBook(input: { title: "Refactoring", genre: TECH, authorId: 4, pages: 448 }) { id } }
```
curl equivalent: `curl -N -H "Accept: text/event-stream" -H "Content-Type: application/json" -d '{"query":"subscription { bookAdded { title } }"}' localhost:8080/graphql`

**Errors**
```graphql
{ requireBook(id: 99) { title } }                  # NOT_FOUND, data is null because the field is non-null
{ book(id: 99) { title } }                         # no error: nullable field returns null
mutation { addBook(input: { title: "", genre: TECH, pages: 0, authorId: 1 }) { id } }   # two BAD_REQUEST errors
mutation { addAuthor(input: { name: "X", email: "nope" }) { id } }                      # custom scalar rejects the value
```

**Context from an HTTP header**
```bash
curl -H "X-User: alice" -H "Content-Type: application/json" -d '{"query":"{ whoami }"}' localhost:8080/graphql
```

**Introspection**
```graphql
{ __schema { queryType { name } types { name kind } } }
{ __type(name: "Book") { fields(includeDeprecated: true) { name isDeprecated deprecationReason } } }
```
