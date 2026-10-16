package com.example.graphqldemo.web;

import com.example.graphqldemo.model.Author;
import com.example.graphqldemo.model.Book;
import com.example.graphqldemo.model.Review;
import com.example.graphqldemo.repo.AuthorRepository;
import com.example.graphqldemo.repo.BookRepository;
import com.example.graphqldemo.repo.ReviewRepository;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.function.Function;
import java.util.stream.Collectors;
import org.springframework.graphql.data.method.annotation.BatchMapping;
import org.springframework.graphql.data.method.annotation.SchemaMapping;
import org.springframework.stereotype.Controller;

/**
 * Resolvers for fields on object types. @BatchMapping methods are backed by a DataLoader:
 * all pending parents of one request are resolved with a single query (no N+1 problem).
 */
@Controller
class FieldResolvers {

    private final AuthorRepository authors;
    private final BookRepository books;
    private final ReviewRepository reviews;

    FieldResolvers(AuthorRepository authors, BookRepository books, ReviewRepository reviews) {
        this.authors = authors;
        this.books = books;
        this.reviews = reviews;
    }

    @BatchMapping
    Map<Book, Author> author(List<Book> bookList) {
        Map<Long, Author> byId = authors.findAllById(bookList.stream().map(Book::getAuthorId).distinct().toList())
                .stream().collect(Collectors.toMap(Author::getId, Function.identity()));
        Map<Book, Author> result = new HashMap<>();
        bookList.forEach(b -> result.put(b, byId.get(b.getAuthorId())));
        return result;
    }

    @BatchMapping
    Map<Book, List<Review>> reviews(List<Book> bookList) {
        Map<Long, List<Review>> byBook = reviews.findByBookIdIn(bookList.stream().map(Book::getId).toList())
                .stream().collect(Collectors.groupingBy(Review::getBookId));
        Map<Book, List<Review>> result = new HashMap<>();
        bookList.forEach(b -> result.put(b, byBook.getOrDefault(b.getId(), List.of())));
        return result;
    }

    @BatchMapping
    Map<Author, List<Book>> books(List<Author> authorList) {
        Map<Long, List<Book>> byAuthor = books.findByAuthorIdIn(authorList.stream().map(Author::getId).toList())
                .stream().collect(Collectors.groupingBy(Book::getAuthorId));
        Map<Author, List<Book>> result = new HashMap<>();
        authorList.forEach(a -> result.put(a, byAuthor.getOrDefault(a.getId(), List.of())));
        return result;
    }

    @BatchMapping
    Map<Review, Book> book(List<Review> reviewList) {
        Map<Long, Book> byId = books.findAllById(reviewList.stream().map(Review::getBookId).distinct().toList())
                .stream().collect(Collectors.toMap(Book::getId, Function.identity()));
        Map<Review, Book> result = new HashMap<>();
        reviewList.forEach(r -> result.put(r, byId.get(r.getBookId())));
        return result;
    }

    /** Plain per-object resolver; the @upper directive in the schema upper-cases the value. */
    @SchemaMapping
    String shoutName(Author author) {
        return author.getName();
    }

    @SchemaMapping
    String legacyCode(Book book) {
        return "LEG-" + book.getId();
    }
}
