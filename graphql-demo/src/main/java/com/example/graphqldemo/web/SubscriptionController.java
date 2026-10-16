package com.example.graphqldemo.web;

import com.example.graphqldemo.model.Book;
import com.example.graphqldemo.model.Genre;
import com.example.graphqldemo.model.Review;
import org.springframework.graphql.data.method.annotation.Argument;
import org.springframework.graphql.data.method.annotation.SubscriptionMapping;
import org.springframework.stereotype.Controller;
import reactor.core.publisher.Flux;

/** Root Subscription fields: each returns a Flux, every element is pushed to the client. */
@Controller
class SubscriptionController {

    private final LibraryEvents events;

    SubscriptionController(LibraryEvents events) {
        this.events = events;
    }

    @SubscriptionMapping
    Flux<Book> bookAdded(@Argument Genre genre) {
        return events.books().filter(book -> genre == null || book.getGenre() == genre);
    }

    @SubscriptionMapping
    Flux<Review> reviewAdded(@Argument Long bookId) {
        return events.reviews().filter(review -> review.getBookId().equals(bookId));
    }
}
