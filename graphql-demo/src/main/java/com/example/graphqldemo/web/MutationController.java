package com.example.graphqldemo.web;

import com.example.graphqldemo.model.Author;
import com.example.graphqldemo.model.Book;
import com.example.graphqldemo.model.Inputs.AuthorInput;
import com.example.graphqldemo.model.Inputs.BookInput;
import com.example.graphqldemo.model.Inputs.BookUpdateInput;
import com.example.graphqldemo.model.Inputs.ReviewInput;
import com.example.graphqldemo.model.Review;
import com.example.graphqldemo.repo.AuthorRepository;
import com.example.graphqldemo.repo.BookRepository;
import com.example.graphqldemo.repo.ReviewRepository;
import jakarta.validation.Valid;
import org.springframework.graphql.data.method.annotation.Argument;
import org.springframework.graphql.data.method.annotation.MutationMapping;
import org.springframework.stereotype.Controller;

/** Root Mutation fields. Invalid input (@Valid) is reported as a BAD_REQUEST error. */
@Controller
class MutationController {

    private final AuthorRepository authors;
    private final BookRepository books;
    private final ReviewRepository reviews;
    private final LibraryEvents events;

    MutationController(AuthorRepository authors, BookRepository books, ReviewRepository reviews,
                       LibraryEvents events) {
        this.authors = authors;
        this.books = books;
        this.reviews = reviews;
        this.events = events;
    }

    @MutationMapping
    Author addAuthor(@Argument @Valid AuthorInput input) {
        return authors.save(new Author(input.name(), input.email(), input.born()));
    }

    @MutationMapping
    Book addBook(@Argument @Valid BookInput input) {
        requireAuthor(input.authorId());
        Book book = books.save(new Book(input.title(), input.genre(), input.published(), input.pages(),
                input.authorId()));
        events.bookAdded(book);
        return book;
    }

    @MutationMapping
    Book updateBook(@Argument Long id, @Argument @Valid BookUpdateInput input) {
        Book book = books.findById(id).orElse(null);
        if (book == null) {
            return null;
        }
        if (input.title() != null) book.setTitle(input.title());
        if (input.genre() != null) book.setGenre(input.genre());
        if (input.published() != null) book.setPublished(input.published());
        if (input.pages() != null) book.setPages(input.pages());
        return books.save(book);
    }

    @MutationMapping
    boolean deleteBook(@Argument Long id) {
        if (!books.existsById(id)) {
            return false;
        }
        books.deleteById(id);
        return true;
    }

    @MutationMapping
    Review addReview(@Argument @Valid ReviewInput input) {
        if (!books.existsById(input.bookId())) {
            throw new NotFoundException("Book " + input.bookId() + " does not exist");
        }
        Review review = reviews.save(new Review(input.bookId(), input.rating(), input.comment()));
        events.reviewAdded(review);
        return review;
    }

    private void requireAuthor(Long id) {
        if (!authors.existsById(id)) {
            throw new NotFoundException("Author " + id + " does not exist");
        }
    }
}
