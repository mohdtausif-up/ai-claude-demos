package com.example.graphqldemo.model;

import jakarta.persistence.Entity;
import jakarta.persistence.GeneratedValue;
import jakarta.persistence.GenerationType;
import jakarta.persistence.Id;

@Entity
public class Review {

    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    private Long id;
    private Long bookId;
    private int rating;
    private String comment;

    protected Review() {
    }

    public Review(Long bookId, int rating, String comment) {
        this.bookId = bookId;
        this.rating = rating;
        this.comment = comment;
    }

    public Long getId() { return id; }
    public Long getBookId() { return bookId; }
    public int getRating() { return rating; }
    public String getComment() { return comment; }
}
