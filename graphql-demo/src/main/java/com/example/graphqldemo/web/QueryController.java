package com.example.graphqldemo.web;

import com.example.graphqldemo.model.Author;
import com.example.graphqldemo.model.Book;
import com.example.graphqldemo.model.BookOrder;
import com.example.graphqldemo.model.Inputs.BookFilter;
import com.example.graphqldemo.repo.AuthorRepository;
import com.example.graphqldemo.repo.BookRepository;
import com.example.graphqldemo.repo.ReviewRepository;
import java.util.ArrayList;
import java.util.List;
import org.springframework.data.domain.Limit;
import org.springframework.data.domain.ScrollPosition;
import org.springframework.data.domain.Sort;
import org.springframework.data.domain.Window;
import org.springframework.data.jpa.domain.Specification;
import org.springframework.graphql.data.method.annotation.Argument;
import org.springframework.graphql.data.method.annotation.ContextValue;
import org.springframework.graphql.data.method.annotation.QueryMapping;
import org.springframework.graphql.data.query.ScrollSubrange;
import org.springframework.stereotype.Controller;

/** Root Query fields. */
@Controller
class QueryController {

    private final AuthorRepository authors;
    private final BookRepository books;
    private final ReviewRepository reviews;

    QueryController(AuthorRepository authors, BookRepository books, ReviewRepository reviews) {
        this.authors = authors;
        this.books = books;
        this.reviews = reviews;
    }

    @QueryMapping
    String hello(@Argument String name) {
        return "Hello, " + name + "!";
    }

    @QueryMapping
    String whoami(@ContextValue String user) {
        return user;
    }

    @QueryMapping
    Book book(@Argument Long id) {
        return books.findById(id).orElse(null);
    }

    @QueryMapping
    Book requireBook(@Argument Long id) {
        return books.findById(id).orElseThrow(() -> new NotFoundException("Book " + id + " does not exist"));
    }

    @QueryMapping
    List<Book> books(@Argument BookFilter filter, @Argument BookOrder orderBy) {
        Specification<Book> spec = Specification.unrestricted();
        if (filter != null) {
            if (filter.genre() != null) {
                spec = spec.and((root, query, cb) -> cb.equal(root.get("genre"), filter.genre()));
            }
            if (filter.titleContains() != null) {
                spec = spec.and((root, query, cb) ->
                        cb.like(cb.lower(root.get("title")), "%" + filter.titleContains().toLowerCase() + "%"));
            }
            if (filter.minPages() != null) {
                spec = spec.and((root, query, cb) -> cb.greaterThanOrEqualTo(root.get("pages"), filter.minPages()));
            }
        }
        return books.findAll(spec, Sort.by(orderBy.property()));
    }

    /** Returning a Window is enough: Spring GraphQL turns it into BookConnection with edges, cursors and pageInfo. */
    @QueryMapping
    Window<Book> booksConnection(ScrollSubrange subrange) {
        ScrollPosition position = subrange.position().orElse(ScrollPosition.keyset());
        return books.findAllByOrderByIdAsc(position, Limit.of(subrange.count().orElse(3)));
    }

    @QueryMapping
    List<Author> authors() {
        return authors.findAll();
    }

    @QueryMapping
    Author author(@Argument Long id) {
        return authors.findById(id).orElse(null);
    }

    /** Returns a mix of types; Spring resolves the union member from the Java class name. */
    @QueryMapping
    List<Object> search(@Argument String text) {
        List<Object> result = new ArrayList<>();
        result.addAll(authors.findByNameContainingIgnoreCase(text));
        result.addAll(books.findByTitleContainingIgnoreCase(text));
        result.addAll(reviews.findByCommentContainingIgnoreCase(text));
        return result;
    }
}
