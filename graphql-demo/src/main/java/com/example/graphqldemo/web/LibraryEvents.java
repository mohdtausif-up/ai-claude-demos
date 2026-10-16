package com.example.graphqldemo.web;

import com.example.graphqldemo.model.Book;
import com.example.graphqldemo.model.Review;
import org.springframework.stereotype.Component;
import reactor.core.publisher.Flux;
import reactor.core.publisher.Sinks;

/** In-memory event bus feeding the GraphQL subscriptions. */
@Component
class LibraryEvents {

    private final Sinks.Many<Book> books = Sinks.many().multicast().directBestEffort();
    private final Sinks.Many<Review> reviews = Sinks.many().multicast().directBestEffort();

    void bookAdded(Book book) {
        books.tryEmitNext(book);
    }

    void reviewAdded(Review review) {
        reviews.tryEmitNext(review);
    }

    Flux<Book> books() {
        return books.asFlux();
    }

    Flux<Review> reviews() {
        return reviews.asFlux();
    }
}
