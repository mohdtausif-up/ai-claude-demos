package com.example.graphqldemo.repo;

import com.example.graphqldemo.model.Review;
import java.util.Collection;
import java.util.List;
import org.springframework.data.jpa.repository.JpaRepository;

public interface ReviewRepository extends JpaRepository<Review, Long> {

    List<Review> findByBookIdIn(Collection<Long> bookIds);

    List<Review> findByCommentContainingIgnoreCase(String text);
}
