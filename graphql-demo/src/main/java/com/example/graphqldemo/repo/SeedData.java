package com.example.graphqldemo.repo;

import com.example.graphqldemo.model.Author;
import com.example.graphqldemo.model.Book;
import com.example.graphqldemo.model.Genre;
import com.example.graphqldemo.model.Review;
import java.time.LocalDate;
import org.springframework.boot.CommandLineRunner;
import org.springframework.stereotype.Component;

@Component
class SeedData implements CommandLineRunner {

    private final AuthorRepository authors;
    private final BookRepository books;
    private final ReviewRepository reviews;

    SeedData(AuthorRepository authors, BookRepository books, ReviewRepository reviews) {
        this.authors = authors;
        this.books = books;
        this.reviews = reviews;
    }

    @Override
    public void run(String... args) {
        Author leGuin = authors.save(new Author("Ursula K. Le Guin", "ursula@example.com", LocalDate.of(1929, 10, 21)));
        Author asimov = authors.save(new Author("Isaac Asimov", "isaac@example.com", LocalDate.of(1920, 1, 2)));
        Author herbert = authors.save(new Author("Frank Herbert", "frank@example.com", LocalDate.of(1920, 10, 8)));
        Author martin = authors.save(new Author("Robert C. Martin", "bob@example.com", LocalDate.of(1952, 12, 5)));

        Book earthsea = books.save(new Book("A Wizard of Earthsea", Genre.FANTASY, LocalDate.of(1968, 11, 1), 183, leGuin.getId()));
        Book dispossessed = books.save(new Book("The Dispossessed", Genre.SCIENCE_FICTION, LocalDate.of(1974, 5, 1), 387, leGuin.getId()));
        Book foundation = books.save(new Book("Foundation", Genre.SCIENCE_FICTION, LocalDate.of(1951, 6, 1), 255, asimov.getId()));
        Book robot = books.save(new Book("I, Robot", Genre.SCIENCE_FICTION, LocalDate.of(1950, 12, 2), 253, asimov.getId()));
        Book dune = books.save(new Book("Dune", Genre.SCIENCE_FICTION, LocalDate.of(1965, 8, 1), 412, herbert.getId()));
        Book cleanCode = books.save(new Book("Clean Code", Genre.TECH, LocalDate.of(2008, 8, 1), 464, martin.getId()));

        reviews.save(new Review(earthsea.getId(), 5, "A timeless classic"));
        reviews.save(new Review(dispossessed.getId(), 5, "Brilliant political fiction"));
        reviews.save(new Review(foundation.getId(), 4, "Big ideas, thin characters"));
        reviews.save(new Review(dune.getId(), 5, "The best science fiction novel"));
        reviews.save(new Review(dune.getId(), 4, "Dense but rewarding"));
        reviews.save(new Review(cleanCode.getId(), 3, "Useful, some advice is dated"));
        reviews.save(new Review(robot.getId(), 4, "Great short stories"));
    }
}
