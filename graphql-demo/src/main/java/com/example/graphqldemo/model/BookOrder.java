package com.example.graphqldemo.model;

public enum BookOrder {
    TITLE("title"), PUBLISHED("published"), PAGES("pages");

    private final String property;

    BookOrder(String property) {
        this.property = property;
    }

    public String property() {
        return property;
    }
}
